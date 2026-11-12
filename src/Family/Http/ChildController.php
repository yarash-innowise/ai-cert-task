<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Family\Application\AddChild;
use App\Family\Application\AddChildInput;
use App\Family\Application\AddChildToTrainer;
use App\Family\Application\SimilarChildFound;
use App\Family\Domain\PlayerProfile;
use App\Family\Domain\PlayerProfileRepository;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Creating a child and the follow-up "Which Trainers?" prompt (AC-27..AC-30). Route group: Player role. */
final class ChildController
{
    use ActorAware;

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly AddChild $addChild,
        private readonly AddChildToTrainer $addToTrainer,
        private readonly PlayerProfileRepository $profiles,
        private readonly ClientIp $clientIp,
    ) {}

    public function form(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderForm([], [], null);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $photo = $request->getUploadedFiles()['photo'] ?? null;
        $input = AddChildRequest::fromArray($this->body($request), $photo instanceof \Psr\Http\Message\UploadedFileInterface ? $photo : null);
        try {
            $profile = $this->addChild->execute($actor, $input, $this->clientIp->fromRequest($request));
        } catch (SimilarChildFound $similar) {
            return $this->renderForm(self::oldOf($input), [], $similar);
        } catch (ValidationFailed $e) {
            throw $e->forForm(AddChildRequest::TEMPLATE, self::oldOf($input));
        }

        return $this->redirect->to('/family/children/' . $profile->id . '/trainers?new=1');
    }

    /**
     * The AC-29 prompt: one Trainer -> yes/no, several -> checklist, none -> nothing to ask.
     *
     * @param array<string, string> $args
     */
    public function prompt(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $actor = $this->actor($request);
        $profile = $this->owned($actor->id, $args);
        $options = $this->addChild->trainerChoicesFor($actor, $profile);
        if ($options === []) {
            return $this->redirect->to('/family/children?notice=child_created');
        }

        return $this->view->render('family/children/prompt.twig', [
            'page_title' => 'Trainers for ' . $profile->firstName,
            'profile' => $profile,
            'options' => $options,
            'single' => count($options) === 1,
            'errors' => [],
        ]);
    }

    /** @param array<string, string> $args */
    public function savePrompt(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $actor = $this->actor($request);
        $profile = $this->owned($actor->id, $args);
        $body = $this->body($request);

        $ids = [];
        if (($body['answer'] ?? '') === 'yes') {
            $options = $this->addChild->trainerChoicesFor($actor, $profile);
            $ids = count($options) === 1 ? [$options[0]->organizationId] : [];
        } elseif (is_array($body['organization_ids'] ?? null)) {
            $ids = array_values(array_filter($body['organization_ids'], 'is_string'));
        }
        if ($ids !== []) {
            $this->addToTrainer->byTrainers($actor, $profile->id, $ids, $this->clientIp->fromRequest($request));
        }

        return $this->redirect->to('/family/children?notice=' . ($ids === [] ? 'child_created' : 'child_created_with_trainers'));
    }

    /**
     * @param array<string, string> $old
     * @param array<string, string> $errors
     */
    private function renderForm(array $old, array $errors, ?SimilarChildFound $similar): ResponseInterface
    {
        return $this->view->render(AddChildRequest::TEMPLATE, [
            'page_title' => 'Add a child',
            'old' => $old,
            'errors' => $errors,
            'similar' => $similar,
        ]);
    }

    /** @return array<string, string> */
    private static function oldOf(AddChildInput $input): array
    {
        return [
            'first_name' => $input->firstName,
            'last_name' => $input->lastName,
            'birth_date' => $input->birthDate->format('Y-m-d'),
            'gender' => $input->gender->value,
            'school' => $input->school ?? '',
        ];
    }

    /** @param array<string, string> $args */
    private function owned(string $actorId, array $args): PlayerProfile
    {
        $profile = $this->profiles->findOwned((string) ($args['id'] ?? ''), $actorId);

        return $profile !== null && $profile->isChild() ? $profile : throw new NotFound();
    }
}
