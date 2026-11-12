<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use App\Family\Domain\ChildAction;
use App\Family\Domain\ChildCapabilities;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Shared\Http\Exception\Forbidden;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** AC-46 / AC-47: the allowed list and the refused list of a child login. */
final class ChildCapabilityMatrixTest extends FamilyHttpTestCase
{
    private string $org;
    private string $mia;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->org] = $this->trainerWithOrganization();
        $parent = $this->signInAs('player');
        $this->mia = $this->childOf($parent);
        $this->associate($this->mia, $this->org);
        $this->signInAsChildLogin($parent, $this->mia);
    }

    /** @return array<string, array{ChildAction}> */
    public static function allowedActions(): array
    {
        return [
            'browse events' => [ChildAction::BrowseEvents],
            'view content' => [ChildAction::ViewContent],
            'view progress' => [ChildAction::ViewProgress],
            'submit feedback requests' => [ChildAction::SubmitFeedbackRequest],
            'update basic profile' => [ChildAction::UpdateBasicProfile],
            'view tokens' => [ChildAction::ViewTokens],
            'switch own context' => [ChildAction::SwitchOwnContext],
        ];
    }

    /** @return array<string, array{ChildAction}> */
    public static function refusedActions(): array
    {
        return [
            'add Trainers' => [ChildAction::AddTrainer],
            'payment methods' => [ChildAction::ManagePaymentMethods],
            'purchase tokens' => [ChildAction::PurchaseTokens],
            'complete a purchase' => [ChildAction::CompletePurchase],
            'delete the account' => [ChildAction::DeleteAccount],
            'change associations' => [ChildAction::ChangeTrainerAssociations],
            "view the parent's data" => [ChildAction::ViewParentData],
        ];
    }

    private function user(bool $child): CurrentUser
    {
        return new CurrentUser('u1', 'Test', 't@example.com', Role::Player, $child ? 'child' : 'standard', false, true);
    }

    #[Group('AC-46'), DataProvider('allowedActions')]
    public function testEveryAllowedActionIsAllowedForAChild(ChildAction $action): void
    {
        self::assertTrue(ChildCapabilities::allows($action));
        ChildCapabilities::assertAllowed($this->user(true), $action);
        $this->addToAssertionCount(1);
    }

    #[Group('AC-47'), DataProvider('refusedActions')]
    public function testEveryRefusedActionThrowsForbiddenForAChildButNotForAParent(ChildAction $action): void
    {
        self::assertFalse(ChildCapabilities::allows($action));
        ChildCapabilities::assertAllowed($this->user(false), $action);
        $this->expectException(Forbidden::class);

        ChildCapabilities::assertAllowed($this->user(true), $action);
    }

    #[Group('AC-47')]
    public function testTheAllowedAndRefusedListsCoverEveryActionExactlyOnce(): void
    {
        $listed = array_merge(array_column(self::allowedActions(), 0), array_column(self::refusedActions(), 0));

        self::assertEqualsCanonicalizing(ChildAction::cases(), $listed);
        self::assertCount(count(ChildAction::cases()), array_unique(array_map(static fn(ChildAction $a): string => $a->value, $listed)));
    }

    /** @return array<string, array{string, string}> */
    public static function allowedScreens(): array
    {
        return [
            'browse events (view only)' => ['/family/events', 'Events'],
            'assigned content' => ['/family/content', 'Content'],
            'own progress' => ['/family/progress', 'Progress'],
            'feedback requests' => ['/family/feedback', 'Feedback requests'],
            'basic profile' => ['/family/profile', 'Profile'],
            'tokens' => ['/family/tokens', 'Tokens'],
            'context switching' => ['/family/context', 'Choose a Trainer'],
        ];
    }

    #[Group('AC-46'), DataProvider('allowedScreens')]
    public function testEachAllowedScreenIsReachableForAChildLogin(string $path, string $heading): void
    {
        $response = $this->get($path);
        $body = $this->body($response);

        self::assertSame(200, $response->getStatusCode(), $path);
        self::assertStringContainsString('<h1>' . $heading . '</h1>', $body);
        self::assertStringContainsString('Ace Tennis Academy', $body, 'scoped to the child\'s own Trainer');
    }

    #[Group('AC-46')]
    public function testTheEventsScreenIsViewOnlyForAChild(): void
    {
        $body = preg_replace('#<header.*?</header>#s', '', $this->body($this->get('/family/events'))) ?? '';

        self::assertStringNotContainsString('<form', $body);
        self::assertStringNotContainsString('<button', $body);
    }

    #[Group('AC-46')]
    public function testTheChildSeesItsTokenBalance(): void
    {
        self::assertStringContainsString('Balance: <strong>0</strong> tokens', $this->body($this->get('/family/tokens')));
    }

    #[Group('AC-46')]
    public function testTheChildCanSwitchContextThroughTheSelector(): void
    {
        $response = $this->post('/family/context', ['context' => $this->org . ':' . $this->mia]);

        self::assertSame(302, $response->getStatusCode());
    }
}
