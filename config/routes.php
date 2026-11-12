<?php

declare(strict_types=1);

use App\Admin\Http\CreateTrainerController;
use App\Approval\Http\ApprovalController;
use App\Availability\Http\CoachAssignmentsController;
use App\Availability\Http\CoachScheduleController;
use App\Availability\Http\PlayerAvailabilityController;
use App\Availability\Http\TrainerAssignmentController;
use App\Availability\Http\TrainerAvailabilityController;
use App\Approval\Http\ChildRequestsController;
use App\Admin\Http\EditUserController;
use App\Admin\Http\ImpersonationController;
use App\Admin\Http\ImpersonationHistoryController;
use App\Admin\Http\UserLifecycleController;
use App\Admin\Http\UsersController;
use App\Family\Http\ChildController;
use App\Family\Http\ChildEditController;
use App\Family\Http\ContextController;
use App\Family\Http\ChildLoginController;
use App\Family\Http\ChildSettingsController;
use App\Family\Http\ChildrenController;
use App\Family\Http\SelfProfileController;
use App\Identity\Domain\Role;
use App\Identity\Http\DashboardController;
use App\Identity\Http\DenyChildLoginMiddleware;
use App\Identity\Http\EmailVerificationController;
use App\Identity\Http\LoginController;
use App\Identity\Http\LogoutController;
use App\Identity\Http\PasswordResetController;
use App\Identity\Http\PasswordSetController;
use App\Identity\Http\ProfileController;
use App\Identity\Http\RequireAuth;
use App\Identity\Http\RequireGuest;
use App\Identity\Http\RequireRole;
use App\Identity\Http\RequireVerifiedEmail;
use App\Identity\Http\TrainerSetupController;
use App\Integration\Http\EventsPlaceholderController;
use App\Integration\Http\FamilyAreaController;
use App\Onboarding\Http\ChildLinkReviewController;
use App\Organization\Http\AcceptInvitationController;
use App\Organization\Http\BrandingController;
use App\Organization\Http\CoachPublicProfileController;
use App\Organization\Http\TrainerCoachesController;
use App\Organization\Http\TrainerPlayersController;
use App\Onboarding\Http\JoinController;
use App\Onboarding\Http\PrefillRegisterController;
use App\Onboarding\Http\RegisterController;
use App\Onboarding\Http\TrainerLinksController;
use App\Shared\Http\Controller\HomeController;
use App\Shared\Http\MediaController;
use League\Route\RouteGroup;
use League\Route\Router;
use Psr\Container\ContainerInterface;

/**
 * Route table. Each module appends its own routes here as it is built.
 *
 * @return callable(Router, ContainerInterface): void
 */
return static function (Router $router, ContainerInterface $container): void {
    $guest = $container->get(RequireGuest::class);
    $auth = $container->get(RequireAuth::class);
    $verified = $container->get(RequireVerifiedEmail::class);
    assert($guest instanceof RequireGuest && $auth instanceof RequireAuth && $verified instanceof RequireVerifiedEmail);

    $router->get('/', [HomeController::class, 'index']);
    $router->get('/health', [HomeController::class, 'health']);

    // The signed-in user's own profile (any role, a child login included, AC-46/AC-68): the user is always the signed-in one.
    $router->get('/profile', [ProfileController::class, 'form'])->middleware($auth)->middleware($verified);
    $router->post('/profile', [ProfileController::class, 'submit'])->middleware($auth)->middleware($verified);
    // A Coach's public profile for the people of their organization; anyone else gets 404 (CoachPublicProfileTest)
    $router->get('/coaches/{id}', [CoachPublicProfileController::class, 'show'])->middleware($auth)->middleware($verified);

    // Private images (logos, photos): any signed-in user, authorized per file (MediaAuthorizationTest); anything else is 404
    $router->get('/media/{kind}/{id}', [MediaController::class, 'show'])->middleware($auth);

    // Identity: sign in / out, password reset, email verification
    $router->get('/login', [LoginController::class, 'show'])->middleware($guest);
    $router->post('/login', [LoginController::class, 'submit'])->middleware($guest);
    $router->post('/logout', [LogoutController::class, 'logout'])->middleware($auth);
    $router->get('/forgot-password', [PasswordResetController::class, 'forgotForm']);
    $router->post('/forgot-password', [PasswordResetController::class, 'forgotSubmit']);
    $router->get('/reset-password/{token}', [PasswordResetController::class, 'resetForm']);
    $router->post('/reset-password/{token}', [PasswordResetController::class, 'resetSubmit']);
    $router->get('/verify-email/resend', [EmailVerificationController::class, 'resendForm']);
    $router->post('/verify-email/resend', [EmailVerificationController::class, 'resendSubmit']);
    $router->get('/verify-email/{token}', [EmailVerificationController::class, 'show']);
    $router->post('/verify-email/{token}', [EmailVerificationController::class, 'confirm']);

    // Trainer setup link and the forced first password (AC-14, AC-15)
    $router->get('/setup/{token}', [TrainerSetupController::class, 'show']);
    $router->post('/setup/{token}', [TrainerSetupController::class, 'submit']);
    $router->get('/password/set', [PasswordSetController::class, 'form'])->middleware($auth);
    $router->post('/password/set', [PasswordSetController::class, 'submit'])->middleware($auth);

    // Role dashboards: signed in, verified, and the matching role (enforced on the server)
    $dashboards = [
        '/admin' => [Role::SuperAdmin, 'admin'],
        '/trainer' => [Role::Trainer, 'trainer'],
        '/coach' => [Role::Coach, 'coach'],
        '/family' => [Role::Player, 'family'],
    ];
    foreach ($dashboards as $prefix => [$role, $method]) {
        $router->group($prefix, static function (RouteGroup $group) use ($method): void {
            // The Coach's dashboard lists their assignments (AC-67); the other roles keep the placeholder.
            $group->get('/', $method === 'coach' ? [CoachAssignmentsController::class, 'index'] : [DashboardController::class, $method]);
            if (in_array($method, ['trainer', 'family'], true)) {
                $group->get('/events', [EventsPlaceholderController::class, $method]);
            }
        })->middleware($auth)->middleware($verified)->middleware(new RequireRole($role));
    }

    // Public ShareLink landing page (AC-26: every unusable code shows the same message)
    $router->get('/join/{code}', [JoinController::class, 'show']);
    // A signed-in player confirms joining (AC-21) or a child asks the parent (AC-49): POST only, so CSRF, impersonation and audit apply (F-04)
    $router->post('/join/{code}', [JoinController::class, 'confirm'])
        ->middleware($auth)->middleware($verified)->middleware(new RequireRole(Role::Player));
    // A signed-in player answers the family checklist (child logins are refused by the use case, AC-49)
    $router->post('/join/{code}/who', [JoinController::class, 'who'])
        ->middleware($auth)->middleware($verified)->middleware(new RequireRole(Role::Player));
    // Registration through the link (AC-19, AC-20): public; the controller sends signed-in users back to the landing page
    $router->get('/join/{code}/register', [RegisterController::class, 'form']);
    $router->post('/join/{code}/register', [RegisterController::class, 'submit']);

    // Camp-form pre-fill entry point (AC-85): the secret token resolves the pre-fill; a bad token gives an ordinary blank registration
    $router->get('/register/prefill/{token}', [PrefillRegisterController::class, 'form']);
    $router->post('/register/prefill/{token}', [PrefillRegisterController::class, 'submit']);
    // Attaching the pre-fill to the signed-in account is an explicit confirm (POST); the GET above only renders (F-04)
    $router->post('/register/prefill/{token}/attach', [PrefillRegisterController::class, 'attach'])
        ->middleware($auth)->middleware($verified)->middleware(new RequireRole(Role::Player));

    // Trainer: the static player ShareLink. The organization comes from the request context, never the URL.
    $router->group('/trainer/links', static function (RouteGroup $group): void {
        $group->get('/', [TrainerLinksController::class, 'index']);
        $group->post('/generate', [TrainerLinksController::class, 'generate']);
        $group->post('/regenerate', [TrainerLinksController::class, 'regenerate']);
        $group->post('/deactivate', [TrainerLinksController::class, 'deactivate']);
    })->middleware($auth)->middleware($verified)->middleware(new RequireRole(Role::Trainer));

    // Coach invitation link: the secret token itself resolves the invitation (AC-18, AC-26, AC-57, AC-59, AC-60)
    $router->get('/invitations/{token}', [AcceptInvitationController::class, 'show']);
    $router->post('/invitations/{token}', [AcceptInvitationController::class, 'accept']);

    // Trainer: portal branding (logo, primary color, preview, reset). The organization comes from the request context.
    $router->group('/trainer/branding', static function (RouteGroup $group): void {
        $group->get('/', [BrandingController::class, 'form']);
        $group->post('/', [BrandingController::class, 'submit']);
        $group->post('/reset', [BrandingController::class, 'reset']);
    })->middleware($auth)->middleware($verified)->middleware(new RequireRole(Role::Trainer));

    // Trainer: Coaches page (invite, list with status, resend). The organization comes from the request context.
    $router->group('/trainer/coaches', static function (RouteGroup $group): void {
        $group->get('/', [TrainerCoachesController::class, 'index']);
        $group->post('/invite', [TrainerCoachesController::class, 'invite']);
        $group->post('/invitations/{id}/resend', [TrainerCoachesController::class, 'resend']);
        $group->get('/{id}', [TrainerCoachesController::class, 'show']);
    })->middleware($auth)->middleware($verified)->middleware(new RequireRole(Role::Trainer));

    // Trainer: the players of the organization (active associations only) and one player's page
    $router->group('/trainer/players', static function (RouteGroup $group): void {
        $group->get('/', [TrainerPlayersController::class, 'index']);
        $group->get('/{id}', [TrainerPlayersController::class, 'show']);
    })->middleware($auth)->middleware($verified)->middleware(new RequireRole(Role::Trainer));

    // Trainer: players' availability with the day/time filter and Best Times (AC-63), a Coach's schedule, and
    // assigning a Coach to an event with the schedule warning and override (AC-65..AC-67). The organization comes
    // from the request context; an id outside it answers 404.
    $router->group('/trainer', static function (RouteGroup $group): void {
        $group->get('/availability', [TrainerAvailabilityController::class, 'index']);
        $group->get('/availability/{id}', [TrainerAvailabilityController::class, 'show']);
        $group->get('/coaches/{id}/schedule', [CoachScheduleController::class, 'showForTrainer']);
        $group->get('/assignments', [TrainerAssignmentController::class, 'index']);
        $group->post('/assignments', [TrainerAssignmentController::class, 'assign']);
    })->middleware($auth)->middleware($verified)->middleware(new RequireRole(Role::Trainer));

    // Coach: "My Times" and the answer to an assignment (AC-64, AC-67). Only the signed-in Coach's own data is reachable.
    $router->group('/coach', static function (RouteGroup $group): void {
        $group->get('/schedule', [CoachScheduleController::class, 'form']);
        $group->post('/schedule', [CoachScheduleController::class, 'save']);
        $group->post('/assignments/{id}/accept', [CoachAssignmentsController::class, 'accept']);
        $group->post('/assignments/{id}/change-request', [CoachAssignmentsController::class, 'requestChange']);
    })->middleware($auth)->middleware($verified)->middleware(new RequireRole(Role::Coach));

    // Family: children, "Me" profile, Trainer associations (AC-27..AC-35). Player role; the owning
    // parent is checked per profile by PlayerProfilePolicy (a foreign profile answers 404). A child
    // login is refused on all of them (AC-47): they are the parent's data and association controls.
    $router->group('/family', static function (RouteGroup $group): void {
        $group->get('/children', [ChildrenController::class, 'index']);
        $group->get('/children/new', [ChildController::class, 'form']);
        $group->post('/children/new', [ChildController::class, 'submit']);
        $group->get('/children/{id}/trainers', [ChildController::class, 'prompt']);
        $group->post('/children/{id}/trainers', [ChildController::class, 'savePrompt']);
        $group->post('/children/{id}/trainers/add', [ChildrenController::class, 'addByTrainer']);
        $group->post('/children/{id}/trainers/code', [ChildrenController::class, 'addByCode']);
        $group->get('/children/{id}/trainers/{organization}/remove', [ChildrenController::class, 'confirmRemove']);
        $group->post('/children/{id}/trainers/{organization}/remove', [ChildrenController::class, 'remove']);
        $group->get('/children/{id}/edit', [ChildEditController::class, 'form']);
        $group->post('/children/{id}/edit', [ChildEditController::class, 'submit']);
        $group->get('/children/{id}/login', [ChildLoginController::class, 'form']);
        $group->post('/children/{id}/login', [ChildLoginController::class, 'submit']);
        $group->get('/children/{id}/settings', [ChildSettingsController::class, 'form']);
        $group->post('/children/{id}/settings', [ChildSettingsController::class, 'submit']);
        // Weekly availability per player profile; the owner only, a child login is refused (AC-61, AC-62)
        $group->get('/availability', [PlayerAvailabilityController::class, 'index']);
        $group->get('/availability/{id}', [PlayerAvailabilityController::class, 'form']);
        $group->post('/availability/{id}', [PlayerAvailabilityController::class, 'save']);
        $group->get('/me', [SelfProfileController::class, 'form']);
        $group->post('/me', [SelfProfileController::class, 'submit']);
        // The parent reviews a child's request to join a Trainer (AC-49); only the owning parent passes
        $group->get('/join/{code}', [ChildLinkReviewController::class, 'show']);
        $group->post('/join/{code}', [ChildLinkReviewController::class, 'confirm']);
        // Parent-only features of later epics; reserved so a child's direct address is refused (AC-47)
        $group->get('/payment-methods', [FamilyAreaController::class, 'reserved']);
        $group->post('/payment-methods', [FamilyAreaController::class, 'reserved']);
        $group->post('/tokens/buy', [FamilyAreaController::class, 'reserved']);
        $group->post('/purchases/complete', [FamilyAreaController::class, 'reserved']);
        $group->post('/account/delete', [FamilyAreaController::class, 'reserved']);
        // The parent's approvals (AC-40, AC-45): only the child's own parent reaches a request; a child login is refused (403)
        $group->get('/approvals', [ApprovalController::class, 'index']);
        $group->get('/approvals/{id}', [ApprovalController::class, 'show']);
        $group->post('/approvals/{id}/decision', [ApprovalController::class, 'decide']);
    })->middleware($auth)->middleware($verified)->middleware(new RequireRole(Role::Player))->middleware(new DenyChildLoginMiddleware());

    // What both a parent and a child login may use (AC-46): context switching and the view-only stub pages,
    // all scoped to the active Trainer context (AC-24).
    $router->group('/family', static function (RouteGroup $group): void {
        $group->get('/context', [ContextController::class, 'show']);
        $group->post('/context', [ContextController::class, 'switch']);
        $group->get('/content', [FamilyAreaController::class, 'content']);
        $group->get('/progress', [FamilyAreaController::class, 'progress']);
        $group->get('/feedback', [FamilyAreaController::class, 'feedback']);
        $group->get('/profile', [ProfileController::class, 'form']);
        $group->get('/tokens', [FamilyAreaController::class, 'tokens']);
        // A child login asks its parent (AC-38, AC-48): each POST only creates a request; the use cases refuse everybody but a child login
        $group->get('/requests', [ChildRequestsController::class, 'index']);
        $group->post('/requests/purchase', [ChildRequestsController::class, 'purchase']);
        $group->post('/requests/rsvp', [ChildRequestsController::class, 'rsvp']);
        $group->post('/requests/rsvp-cancel', [ChildRequestsController::class, 'rsvpCancel']);
        $group->post('/requests/token-spend', [ChildRequestsController::class, 'tokenSpend']);
    })->middleware($auth)->middleware($verified)->middleware(new RequireRole(Role::Player));

    // Super Admin tools (AC-9: Super Admin only, enforced here on the server)
    $router->group('/admin/users', static function (RouteGroup $group): void {
        $group->get('/', [UsersController::class, 'index']);
        $group->get('/new', [CreateTrainerController::class, 'form']);
        $group->post('/new', [CreateTrainerController::class, 'submit']);
        $group->get('/{id}/edit', [EditUserController::class, 'form']);
        $group->post('/{id}/edit', [EditUserController::class, 'submit']);
        // Lifecycle (AC-72..AC-79): confirmation pages first; impersonation (AC-50, AC-51) names user and role first
        $group->get('/{id}/deactivate', [UserLifecycleController::class, 'deactivateForm']);
        $group->post('/{id}/deactivate', [UserLifecycleController::class, 'deactivate']);
        $group->post('/{id}/reactivate', [UserLifecycleController::class, 'reactivate']);
        $group->get('/{id}/delete', [UserLifecycleController::class, 'deleteForm']);
        $group->post('/{id}/delete', [UserLifecycleController::class, 'delete']);
        $group->get('/{id}/impersonate', [ImpersonationController::class, 'confirm']);
        $group->post('/{id}/impersonate', [ImpersonationController::class, 'start']);
    })->middleware($auth)->middleware($verified)->middleware(new RequireRole(Role::SuperAdmin));

    // Impersonation History report (AC-56)
    $router->get('/admin/impersonations', [ImpersonationHistoryController::class, 'index'])
        ->middleware($auth)->middleware($verified)->middleware(new RequireRole(Role::SuperAdmin));

    // Exit Impersonation (AC-53): not behind the Super Admin gate, because while impersonating the effective user is the
    // impersonated one; the use case only acts when the session really is an impersonation (otherwise 404).
    $router->post('/impersonation/exit', [ImpersonationController::class, 'exit'])->middleware($auth);
};
