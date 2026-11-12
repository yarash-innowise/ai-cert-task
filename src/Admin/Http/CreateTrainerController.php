<?php

declare(strict_types=1);

namespace App\Admin\Http;

use App\Admin\Application\CreateTrainer;
use App\Identity\Domain\CurrentUser;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Super Admin only (route gate); the use case acts for the signed-in admin. */
final class CreateTrainerController
{
    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly CreateTrainer $createTrainer,
        private readonly ClientIp $clientIp,
    ) {}

    public function form(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->render('admin/users/new.twig', ['page_title' => 'Create a Trainer', 'old' => [], 'errors' => []]);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $request->getAttribute('current_user');
        if (!$actor instanceof CurrentUser) {
            throw new Forbidden();
        }

        $input = CreateTrainerRequest::fromArray((array) $request->getParsedBody());
        try {
            $this->createTrainer->execute($input, $actor->id, $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            throw $e->forForm('admin/users/new.twig', [
                'business_name' => $input->businessName,
                'trainer_name' => $input->trainerName,
                'email' => $input->email->value,
                'phone' => $input->phone->e164,
            ]);
        }

        return $this->redirect->to('/admin/users?notice=trainer_created');
    }
}
