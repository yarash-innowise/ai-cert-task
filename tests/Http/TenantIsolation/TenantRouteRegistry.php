<?php

declare(strict_types=1);

namespace Tests\Http\TenantIsolation;

/**
 * The isolation harness's list of routes. EVERY route of the application must be classified here:
 *
 *  - {@see self::tenantRoutes()}: routes that expose one organization's data. TenantIsolationTest
 *    calls each of them with another organization's identifiers and expects 404.
 *  - {@see self::nonTenantRoutes()}: routes that expose no tenant data (public pages, sign-in,
 *    own-account pages, Super Admin tools). Adding a route here is a claim a reviewer checks.
 *
 * A route in neither list fails TenantIsolationTest, so a new tenant route cannot ship unchecked.
 * Every later task that adds a route registers it in one of the two lists in the same change.
 */
final class TenantRouteRegistry
{
    /**
     * Route key => builder of the path parameters that point at a resource of the FOREIGN
     * organization (the other Trainer's). The foreign organization id is given as `$foreignOrganizationId`.
     *
     * @return array<string, callable(string): array<string, string>>
     */
    public static function tenantRoutes(): array
    {
        return [
            // Test-only fixture routes (see TenantFixtureRoutes) that demonstrate the convention.
            'GET ' . TenantFixtureRoutes::ORGANIZATION => static fn(string $foreignOrganizationId): array => ['id' => $foreignOrganizationId],
            'POST ' . TenantFixtureRoutes::ORGANIZATION => static fn(string $foreignOrganizationId): array => ['id' => $foreignOrganizationId],
        ];
    }

    /**
     * Routes that carry no tenant data, as "METHOD /path" with the router's placeholders.
     *
     * @return list<string>
     */
    public static function nonTenantRoutes(): array
    {
        return [
            // Public pages and health
            'GET /', 'GET /health',
            // Own profile (the signed-in user only, no id in the address) and a Coach's public profile (a Coach outside the
            // viewer's organization answers 404, CoachPublicProfileTest)
            'GET /profile', 'POST /profile', 'GET /coaches/{id}',
            // Media: authorized per file (organization members for logos; owner, guardian and associated staff for photos);
            // a file the viewer may not see answers 404 (MediaAuthorizationTest)
            'GET /media/{kind}/{id}',
            // Identity: sign in/out, password and email flows (own account only)
            'GET /login', 'POST /login', 'POST /logout',
            'GET /forgot-password', 'POST /forgot-password',
            'GET /reset-password/{token}', 'POST /reset-password/{token}',
            'GET /verify-email/resend', 'POST /verify-email/resend',
            'GET /verify-email/{token}', 'POST /verify-email/{token}',
            'GET /setup/{token}', 'POST /setup/{token}',
            'GET /password/set', 'POST /password/set',
            // Role dashboards (placeholders, no tenant data yet)
            'GET /admin', 'GET /trainer', 'GET /coach', 'GET /family',
            // Events placeholders: no resource id; the data is scoped by the RequestContext (the active organization)
            'GET /trainer/events', 'GET /family/events',
            // Public ShareLink landing: the unguessable code itself resolves the organization; nothing else is exposed
            'GET /join/{code}', 'POST /join/{code}', 'GET /join/{code}/register', 'POST /join/{code}/register',
            // Camp-form pre-fill entry point: the unguessable token resolves the pre-fill; a bad token is a blank ordinary registration (AC-85)
            'GET /register/prefill/{token}', 'POST /register/prefill/{token}', 'POST /register/prefill/{token}/attach',
            // Trainer ShareLink page: no id in the URL, the organization is the request context's (like the events placeholders)
            'GET /trainer/links', 'POST /trainer/links/generate', 'POST /trainer/links/regenerate', 'POST /trainer/links/deactivate',
            // Coach invitation link: the unguessable token resolves the invitation; nothing else is exposed (AC-26)
            'GET /invitations/{token}', 'POST /invitations/{token}',
            // Trainer Coaches page: no tenant id in the URL (the organization is the request context's); a foreign invitation id answers 404 (CoachInvitationEdgeTest)
            'GET /trainer/coaches', 'POST /trainer/coaches/invite', 'POST /trainer/coaches/invitations/{id}/resend',
            // Trainer branding: no id in the URL, the organization is the request context's (BrandingIsolationTest)
            'GET /trainer/branding', 'POST /trainer/branding', 'POST /trainer/branding/reset',
            // Trainer rosters: scoped by the request context's organization; an id outside it answers 404 (TrainerIsolationTest)
            'GET /trainer/players', 'GET /trainer/players/{id}', 'GET /trainer/coaches/{id}',
            // Family: the parent's own account data (children, Me, associations); a foreign profile answers 404 via PlayerProfilePolicy
            'GET /family/children', 'GET /family/children/new', 'POST /family/children/new',
            'GET /family/children/{id}/trainers', 'POST /family/children/{id}/trainers',
            'POST /family/children/{id}/trainers/add', 'POST /family/children/{id}/trainers/code',
            'GET /family/children/{id}/trainers/{organization}/remove', 'POST /family/children/{id}/trainers/{organization}/remove',
            'GET /family/children/{id}/edit', 'POST /family/children/{id}/edit',
            'GET /family/children/{id}/login', 'POST /family/children/{id}/login',
            'GET /family/children/{id}/settings', 'POST /family/children/{id}/settings',
            'GET /family/me', 'POST /family/me',
            // Context selector: the choice is validated against the user's own associations (a foreign context answers 404)
            'GET /family/context', 'POST /family/context',
            // Child-link review: the parent must own the child profile named in the request (a foreign one answers 404)
            'GET /family/join/{code}', 'POST /family/join/{code}', 'POST /join/{code}/who',
            // Player/child pages scoped by the RequestContext (the active Trainer), like the events pages; no resource ids
            'GET /family/content', 'GET /family/progress', 'GET /family/feedback', 'GET /family/profile', 'GET /family/tokens',
            // A child login's approval requests: reached through the signed-in child's own profile only, never through an id in the URL
            'GET /family/requests', 'POST /family/requests/purchase', 'POST /family/requests/rsvp', 'POST /family/requests/rsvp-cancel', 'POST /family/requests/token-spend',
            // The parent's approvals: a request that is not one of the parent's own children's answers 404 via ApprovalRequestPolicy (DecideRequestTest)
            'GET /family/approvals', 'GET /family/approvals/{id}', 'POST /family/approvals/{id}/decision',
            // Reserved parent-only features of later epics: refuse child logins, expose nothing
            'GET /family/payment-methods', 'POST /family/payment-methods', 'POST /family/tokens/buy', 'POST /family/purchases/complete', 'POST /family/account/delete',
            // Availability: the Trainer's pages are scoped by the request context's organization and answer 404 for a player or
            // Coach outside it (AvailabilityIsolationTest, CoachScheduleTest, AssignCoachTest); a parent edits only their own profiles
            // (SaveAvailabilityTest); a Coach reaches only their own schedule and assignments (no id of someone else's in the address)
            'GET /trainer/availability', 'GET /trainer/availability/{id}', 'GET /trainer/coaches/{id}/schedule',
            'GET /trainer/assignments', 'POST /trainer/assignments',
            'GET /coach/schedule', 'POST /coach/schedule', 'POST /coach/assignments/{id}/accept', 'POST /coach/assignments/{id}/change-request',
            'GET /family/availability', 'GET /family/availability/{id}', 'POST /family/availability/{id}',
            // Super Admin tools: platform-wide by design, gated by RequireRole(SuperAdmin)
            'GET /admin/users', 'GET /admin/users/new', 'POST /admin/users/new',
            'GET /admin/users/{id}/edit', 'POST /admin/users/{id}/edit',
            'GET /admin/users/{id}/deactivate', 'POST /admin/users/{id}/deactivate', 'POST /admin/users/{id}/reactivate',
            'GET /admin/users/{id}/delete', 'POST /admin/users/{id}/delete',
            'GET /admin/users/{id}/impersonate', 'POST /admin/users/{id}/impersonate',
            'GET /admin/impersonations',
            // Exit Impersonation: acts only on the signed-in session's own impersonation, no id in the address
            'POST /impersonation/exit',
            // Test-only
            'GET ' . TenantFixtureRoutes::CONTEXT,
        ];
    }

    /**
     * @param list<string> $routeTable "METHOD /path" of every registered route
     * @return list<string> routes that are in neither list
     */
    public static function unclassified(array $routeTable): array
    {
        $known = [...array_keys(self::tenantRoutes()), ...self::nonTenantRoutes()];

        return array_values(array_diff($routeTable, $known));
    }
}
