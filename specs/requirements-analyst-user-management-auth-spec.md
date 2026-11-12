# Spec: User Management & Authentication

Source: `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md` (Epic-01, P0 foundation). IDs US-01.01..US-01.14 are the epic's real story IDs.

## Problem

The platform serves four kinds of people with different needs and permissions: the platform owner (Super Admin), Trainers who run their own training businesses, Coaches who deliver sessions for one Trainer, and Players/Parents (families who may train with several Trainers). Today there is no way to sign in, tell these people apart, onboard them, or keep one Trainer's data away from another's. A single generic user type would leak data between Trainer organizations and give every user the wrong screens.

Every later epic (events, CRM, content, payments, marketing, admin tools) depends on this one. Success means: each user reaches only what their role allows, Trainers onboard players and coaches in under 5 minutes by share link, Super Admin resolves user problems without a code deployment, no data crosses Trainer organizations, and 95%+ of parent/child profile creations succeed without help.

## User scenarios

### Authentication and access (epic in-scope: auth, RBAC, sessions, reset, verification)

S1. **Any user** signs in with email and password and lands on the dashboard for their role.
   Path: login page, enter credentials, see role dashboard; sign out ends the session.
S2. **Any user** who forgot their password requests a reset link by email and sets a new password.
   Path: "Forgot password", enter email, open emailed link within 1 hour, choose new password.
S3. **New user** confirms their email address through a link sent after registration or account creation.
   Path: open verification email, click link within 24 hours.
S4. **Any user** is stopped from reaching screens or data their role does not permit.
   Path: any request for a forbidden page or record is refused, both in what is shown and in what is enforced.

### US-01.01 Super Admin creates a Trainer

S5. **Super Admin** creates a Trainer account so the Trainer can start running a business.
   Path: Users tool, "Create User", role Trainer, enter business name, trainer name, email, phone; Trainer is emailed credentials or a setup link and must set their own password at first login.

### US-01.02 Player registers via ShareLink

S6. **New Player/Parent** registers from a Trainer's static link and is attached to that Trainer.
   Path: open link, registration form (name, email, password, phone, player name/age/gender), account and player profile created, linked to the Trainer, confirmation email sent.
S7. **Existing logged-in Player** opens a second Trainer's link and is added to that Trainer without a duplicate account.
   Path: open link while logged in, instant association, land on that Trainer's events.
S8. **Parent with children** opens a new Trainer's link and chooses who in the family joins.
   Path: prompt "Who will train with [Trainer]?" with a checklist (Me + each child); only the ticked people are associated.
S9. **Player/Parent with several Trainers** switches between Trainer contexts, each fully separate.
   Path: context switcher in navigation; calendar, tokens, content and reservations shown are only the chosen Trainer's; the last chosen context persists across the session.

### US-01.03 / US-01.04 Parent manages children and their Trainers

S10. **Parent** creates a child profile and chooses which Trainers the child trains with.
    Path: Player Profiles, "+ Add Child", enter name/age/gender (optional school, photo); one Trainer gives a yes/no prompt, several give a checklist, none leaves the child unassociated.
S11. **Parent** registers themselves as a player so they can train too.
    Path: the parent account is itself a player profile ("Me").
S12. **Parent** adds a child to a Trainer later, by entering a ShareLink or picking from "My Trainers".
    Path: Family section, child, "Add Trainer", confirm.
S13. **Parent** removes a child from a Trainer.
    Path: "Remove" on the child-Trainer pair, confirm warning about cancelled RSVPs; the child's data with that Trainer is soft-deleted and the Trainer's roster no longer shows the child.
S14. **Parent** uses a context selector showing "Me" and each child with each Trainer (or only children if the parent does not train).
    Path: dropdown grouped "Your Training" / "Your Children's Training".

### US-01.05 Child purchase approval (workflow only; payment is Epic-05)

S15. **Child** requests a purchase that needs money (USD) and it waits for a parent decision.
    Path: child checks out, request becomes "Pending Parent Approval", parent is notified by email and in-app, parent approves, denies, or asks for more info, with an optional note; child sees the outcome.
S16. **Parent** controls token spending per child.
    Path: per-child setting "Allow token spending without approval" (default OFF); OFF routes token spends through the same approval; ON lets the child spend directly and informs the parent.
S17. **Unanswered request** expires after 48 hours.
    Path: auto-deny, both parent and child notified.

### US-01.06 Child login with constraints

S18. **Child** with their own login sees only what a child may do.
    Path: child signs in; allowed actions are available, forbidden ones (see AC list) are absent and refused if attempted.
S19. **Child** opens a Trainer link and is blocked, and the parent is told.
    Path: child clicks link, sees "Ask your parent to register you with this trainer", parent is emailed a link to review the registration; child stays unassociated until the parent acts.
S20. **Child** with several Trainers switches between their own Trainer contexts only (no "Me" section, no parent data).

### US-01.07 / 01.12 / 01.13 Super Admin supports and removes users

S21. **Super Admin** views the platform as another user (not another Super Admin) to troubleshoot.
    Path: Users tool, "Impersonate", confirm, sticky banner "Viewing as [Name] | Exit Impersonation", exit returns to admin view; start/end/duration logged; auto-ends after 1 hour.
S22. **Super Admin** reviews impersonation history for compliance.
S23. **Super Admin** deactivates a user (cannot log in, history kept) and can reactivate.
S24. **Super Admin** deletes a user for a privacy request: personal data anonymized, history shows "Deleted User", irreversible, logged.
S25. **Super Admin** browses, searches and edits any user in a global directory (Users tool; search is local to this tool, not a global search).

### US-01.08 Trainer invites Coach

S26. **Trainer** invites a Coach by email and a single-use link valid 7 days.
    Path: Coaches section, "Invite Coach", email (optional name/message); invitation shown as Pending/Accepted/Expired; Coach registers or logs in and joins that Trainer.
S27. **Trainer** resends an expired invitation.
S28. **Trainer** generates, shares and manages ShareLinks (static for players, unique for coaches) and manages only their own organization's users.

### US-01.09 / 01.10 Availability

S29. **Player/Parent** sets weekly availability per player profile (per child for a parent).
S30. **Trainer** views player availability, filters players by a day/time, and sees a "Best Times" summary on player cards.
S31. **Coach** sets recurring weekly availability ("My Times") with several slots per day.
S32. **Trainer** assigns a Coach to a conflicting time, sees a warning, and overrides with a required reason; Coach still sees the assignment and may accept or ask for a change. (Specced against an "event" reference only; events are Epic-02.)

### US-01.11 Profile and US-01.14 Branding

S33. **Any user** edits their own profile (fields per role, read-only fields locked).
S34. **Coach** manages a public profile (bio, credentials, certifications, visibility checkbox).
S35. **Trainer** sets portal branding: one logo and one primary color; applies at once to the Trainer's organization; can reset to default.

### Camp-form entry point (conversion itself is Epic-08)

S36. **Camp/evaluation form submitter** arrives at registration with the form's data pre-filled, creates an account without re-typing, and is auto-assigned to the Trainer; alternatively receives a ShareLink by email to register later.

## Acceptance criteria

### Authentication, sessions, access control
- [ ] **AC-1** A user with a valid email and password can sign in; each of the four roles lands on its own role dashboard. (Epic-level, US-01.01)
- [ ] **AC-2** A wrong email or password is rejected with a message that does not reveal which of the two was wrong. (Epic-level)
- [ ] **AC-3** Repeated failed sign-in attempts are rate-limited; after the limit, further attempts are refused for a period before another try is allowed. Limit and period are an open question (Q-01.10). (Epic-level)
- [ ] **AC-4** Passwords are never stored or shown in a recoverable form. (Epic-level)
- [ ] **AC-5** A user can request a password reset by email; the emailed link works once and expires 1 hour after being issued; a used or expired link is refused with a clear message and an option to request a new one. (Epic-level)
- [ ] **AC-6** Email addresses are unique across all users; creating or registering with an already-registered email is refused with a clear message. (US-01.01, US-01.02)
- [ ] **AC-7** A verification email link confirms the address and expires 24 hours after being issued; whether login is blocked before verification is decided by Q-01.05. (Epic-level)
- [ ] **AC-8** A session ends when the user signs out and when it has been inactive or open longer than the agreed timeout (Q-01.07); an ended session requires a fresh login. (Epic-level)
- [ ] **AC-9** Every user has exactly one role (Super Admin, Trainer, Coach, Player/Parent) and can only reach features and data permitted to that role; this is enforced on the server, not only by hiding screens. (Epic-level)
- [ ] **AC-10** A Trainer can see and manage only users and data of their own organization; no data from another Trainer's organization is ever visible. (Epic-level, Business Rules)
- [ ] **AC-11** Every state-changing action is protected against forged cross-site requests. (Epic security notes)
- [ ] **AC-12** Self-registration as a Trainer is not possible; only a Super Admin can create Trainer accounts. (US-01.01)

### US-01.01 Create Trainer
- [ ] **AC-13** Super Admin can create a Trainer by entering business name, trainer name, email and phone from the Users tool; all of these are required. (US-01.01)
- [ ] **AC-14** The new Trainer receives an email with login credentials or a setup link (Q-01.08 decides which), can log in, and reaches the Trainer dashboard. (US-01.01)
- [ ] **AC-15** If a temporary password is used, the Trainer is forced to change it at first login before doing anything else. (US-01.01)
- [ ] **AC-16** The new Trainer appears in the Users list with status "Active". (US-01.01)
- [ ] **AC-17** The creation is recorded in an audit log: who created the Trainer, when, and the Trainer's details. (US-01.01)

### US-01.02 / ShareLinks / multi-trainer
- [ ] **AC-18** Each ShareLink belongs to exactly one Trainer. A player link is static (unlimited uses, no expiry); a coach link is single-use and expires 7 days after creation. (US-01.02, US-01.08)
- [ ] **AC-19** A visitor who is not logged in and opens a valid player link is taken to registration/login; registration collects name, email, password, phone (parent), and player name/age/gender. (US-01.02)
- [ ] **AC-20** After registration the new account is associated with the Trainer who owns the link, a player profile appears in that Trainer's CRM, and a confirmation email is sent. (US-01.02)
- [ ] **AC-21** A logged-in player opening a Trainer's link sees a one-click "Join [Trainer]" confirmation; on confirming (a CSRF-protected POST) they are associated immediately and taken to that Trainer's events; no duplicate account is created. Opening the link alone changes nothing. (US-01.02; amended 2026-11-12 by the user after security review F-04)
- [ ] **AC-22** A parent with children opening a new Trainer's link is asked "Who will train with [Trainer]?" with a checklist of Me and each child, and only the ticked people are associated. (US-01.02)
- [ ] **AC-23** Link use is tracked: which link produced each registration, how many times it has been used, and when. (US-01.02, Business Rules)
- [ ] **AC-24** A player/parent associated with several Trainers can switch between Trainer contexts; each context shows only that Trainer's calendar, tokens, content and reservations, with no combined view. (US-01.02)
- [ ] **AC-25** The chosen Trainer context persists for the rest of the session. (US-01.02)
- [ ] **AC-26** An invalid, deactivated, expired or already-used link shows a clear message and associates nobody. (US-01.02, US-01.08)

### US-01.03 / 01.04 Children
- [ ] **AC-27** A parent can create a child profile with required name, age and gender and optional school and photo; the profile is marked "Child" and linked to the parent account. (US-01.03)
- [ ] **AC-28** A child's age must be within the range agreed in Q-01.03 (epic text says 1-18); other ages are refused with a clear message. (US-01.03)
- [ ] **AC-29** On creating a child: a parent with exactly one Trainer is asked "Will [Child] also train with [Trainer]?"; a parent with several sees a Trainer checklist; Yes/ticked associates the child with those Trainers; No/none leaves the child created but unassociated. (US-01.03)
- [ ] **AC-30** Creating a child whose name and age resemble an existing child of the same parent shows a warning, which the parent can dismiss to proceed. (US-01.03)
- [ ] **AC-31** A parent account can also act as a player ("Me") and train with Trainers itself. (US-01.03, scope list)
- [ ] **AC-32** Each child has their own calendar, RSVP status, attendance and availability per Trainer. (US-01.03)
- [ ] **AC-33** A parent can see every child with name, age and associated Trainers with association dates. (US-01.04)
- [ ] **AC-34** A parent can add a child to a Trainer by entering a ShareLink or picking a Trainer the parent is already associated with; the child then sees that Trainer's events and content. (US-01.04)
- [ ] **AC-35** Removing a child from a Trainer first shows a confirmation that upcoming RSVPs will be cancelled; on confirm, the association ends, upcoming RSVPs are cancelled, the child's data with that Trainer is soft-deleted (history kept) and the Trainer's roster no longer shows the child. (US-01.04)
- [ ] **AC-36** The parent context selector lists "Your Training" (Me with each Trainer) and "Your Children's Training" (each child with each Trainer); a parent who does not train sees only the children section. (US-01.04)
- [ ] **AC-37** A child with their own login sees a context selector listing only their own Trainers. (US-01.04, US-01.06)

### US-01.05 Purchase approval (request workflow only)
- [ ] **AC-38** When a child-login account starts a purchase paid in USD, the purchase is not completed; a request with status "Pending Parent Approval" is created recording child, event, amount, payment type, status and timestamps. (US-01.05)
- [ ] **AC-39** The parent is notified of a new pending request by email and in the app. (US-01.05)
- [ ] **AC-40** The parent can approve, deny, or ask for more info on a request, adding an optional note; the child sees the status change (Pending to Confirmed, Denied, or info requested). (US-01.05)
- [ ] **AC-41** On approval, the system hands the approved purchase to the payment capability (Epic-05) and registers the child for the event only when that capability reports success; this spec defines the hand-off, not the payment. (US-01.05)
- [ ] **AC-42** Each child has a setting "Allow token spending without approval", default OFF, changeable by the parent at any time. (US-01.05)
- [ ] **AC-43** With the setting OFF, token spends follow the same approval workflow as USD. With it ON, the child's token spend proceeds without approval and the parent receives an informational (not approval) notification. (US-01.05)
- [ ] **AC-44** A pending request with no parent action after 48 hours is automatically denied and both parent and child are notified. (US-01.05)
- [ ] **AC-45** A request can be decided only once and only by the child's own parent; a decision on an already-decided or expired request is refused. (US-01.05)

### US-01.06 Child login
- [ ] **AC-46** A child login can: browse eligible events (view only), view assigned content, view own progress, submit feedback requests, update basic profile info (photo, preferences, name, phone, school, jersey number), view tokens, and switch between own Trainer contexts. (US-01.06; "basic profile info" scope confirmed 2026-11-12 by the user, review F-17)
- [ ] **AC-47** A child login cannot: add Trainers, add/remove payment methods, purchase tokens, complete a purchase without parent approval, delete the account, change Trainer associations, or view the parent's training data; each attempt is refused. (US-01.06)
- [ ] **AC-48** Child RSVP and RSVP cancellation require parent approval; the epic text is ambiguous on mechanism (Q-01.09). (US-01.06)
- [ ] **AC-49** When a logged-in child opens a Trainer's link and confirms the request (CSRF-protected POST, amended 2026-11-12 after review F-04; the parent is notified at most once per child and Trainer within 24 hours), the child is shown "Ask your parent to register you with this trainer", is not associated, and the parent is emailed (subject "[Child Name] wants to join [Trainer Name]'s program") with the link and a "Review Registration" button. (US-01.06)

### US-01.07 Impersonation
- [ ] **AC-50** Only a Super Admin can start impersonation, from the Users tool, after a confirmation naming the user and role. (US-01.07)
- [ ] **AC-51** A Super Admin cannot impersonate another Super Admin; the attempt is refused with a validation message. (US-01.07)
- [ ] **AC-52** While impersonating, navigation, permissions and data exactly match the impersonated user, and a sticky, color-distinct banner "Viewing as [User Name] | Exit Impersonation" is always visible. (US-01.07)
- [ ] **AC-53** "Exit Impersonation" returns to the Super Admin's own view. (US-01.07)
- [ ] **AC-54** An impersonation session ends automatically after 1 hour. (US-01.07)
- [ ] **AC-55** Each impersonation is logged with admin, impersonated user, start, end and duration, and every action taken during it is logged with the admin's identity. (US-01.07)
- [ ] **AC-56** Super Admin can open an "Impersonation History" report built from those logs. (US-01.07)

### US-01.08 Coach invitation
- [ ] **AC-57** A Trainer can invite a Coach by email (required; name and message optional), generating a unique single-use link valid 7 days and sending the invitation email. (US-01.08)
- [ ] **AC-58** The Trainer sees each invitation's status (Pending, Accepted, Expired); an accepted Coach appears in the Trainer's Coaches list. (US-01.08)
- [ ] **AC-59** A Coach is active under at most one Trainer at any time; accepting an invitation while active under another Trainer is refused with a clear message. (US-01.08)
- [ ] **AC-60** An expired invitation shows a clear message and the Trainer can resend a new one. (US-01.08)

### US-01.09 / 01.10 Availability
- [ ] **AC-61** A player/parent can set weekly availability per player profile, either as available time ranges or "Not Available" per day; a parent sets it separately for each child. (US-01.09)
- [ ] **AC-62** Saving availability shows the confirmation "Availability saved. Trainers can see these preferences when planning sessions." (US-01.09)
- [ ] **AC-63** A Trainer can view the availability of players in their own organization, filter players by a chosen day/time, and see a "Best Times" summary on each player card; availability is advisory and never blocks scheduling. (US-01.09)
- [ ] **AC-64** A Coach can define a recurring weekly schedule with several time slots per day. (US-01.10)
- [ ] **AC-65** Assigning a Coach to an event outside their availability shows a warning "Coach [Name] is not available at this time per their schedule. Continue anyway?"; continuing requires a non-empty reason. (US-01.10)
- [ ] **AC-66** Each override is logged with event, coach, reason and the Trainer who overrode. (US-01.10)
- [ ] **AC-67** The overridden Coach still sees the assignment (it is not blocked) and can accept or request a change. (US-01.10)

### US-01.11 Profile
- [ ] **AC-68** Any user can edit name, phone, photo and the role-specific fields (Player: school, jersey number; Parent: emergency contact; Coach: bio, credentials, certifications, public-profile checkbox; Trainer: business name, organization details; Super Admin: admin settings). (US-01.11)
- [ ] **AC-69** Email, role, skill level (set by Trainer) and account-created date are read-only to the user. Changing email is a separate flow (Q-01.11). (US-01.11)
- [ ] **AC-70** Invalid phone formats or missing required fields are refused with a message naming the field. (US-01.11)
- [ ] **AC-71** An uploaded profile photo is accepted only if it meets the agreed type and size limits (Q-01.12) and is shown after save as a thumbnail. (US-01.11)

### US-01.12 Deactivate / US-01.13 Delete
- [ ] **AC-72** Super Admin can deactivate a user after a confirmation; the user's status becomes "Inactive". (US-01.12)
- [ ] **AC-73** An inactive user cannot log in and sees "Account deactivated. Contact support."; any existing session of that user ends. (US-01.12)
- [ ] **AC-74** An inactive user's history still appears in analytics, past rosters and CRM (marked "Inactive"). (US-01.12)
- [ ] **AC-75** Super Admin can reactivate an inactive user, who can then log in again. (US-01.12)
- [ ] **AC-76** Super Admin can delete a user after a warning confirmation; name becomes "Deleted User", email becomes "deleted_[user_id]@example.com", phone and personal identifiers are removed and photo becomes the default avatar. (US-01.13)
- [ ] **AC-77** After deletion, historical records remain and show "Deleted User", and analytics totals (player counts, revenue, attendance rates) are unchanged. (US-01.13)
- [ ] **AC-78** A deleted user has status "Deleted", cannot log in and cannot be reactivated. (US-01.13)
- [ ] **AC-79** Each deletion is logged with original user ID, who deleted, when and reason; what original data is retained for legal compliance is decided by Q-01.13. (US-01.13)

### Super Admin Users tool
- [ ] **AC-80** Super Admin has a Users tool listing all users with pagination, search and filters local to that tool, and can edit any user's account and profile. (Scope list, epic-level)

### US-01.14 Branding
- [ ] **AC-81** A Trainer can upload one logo (PNG, JPG or SVG, max 2 MB), see a preview before saving, and after saving the logo appears in the header of that Trainer's portal for their players, coaches and parents. (US-01.14)
- [ ] **AC-82** A Trainer can choose a primary brand color as a hex code, preview it live, save it, and reset to the default. (US-01.14)
- [ ] **AC-83** Saved branding is visible immediately to all users in that Trainer's organization and to no one else. (US-01.14)
- [ ] **AC-84** A logo larger than the recommended 200x200 px is resized automatically; a file of the wrong type or over 2 MB is refused with a clear message. (US-01.14)

### Camp-form entry point
- [ ] **AC-85** A registration entry point accepts pre-filled data from a camp/evaluation submission so the user does not re-enter it, and on completion the new account is auto-assigned to the Trainer; an alternative lets a ShareLink be sent by email for later registration. The form itself and conversion prompt belong to Epic-08. (Scope list, camp-to-user)

### Cross-cutting
- [ ] **AC-86** Emails the epic names are sent: Trainer invite/credentials, registration confirmation, password reset, email verification, coach invitation, parent approval request and approval outcome, child-link notification, token-spend notice; the complete list is Q-01.04. (US-01.01/02/05/06/08)
- [ ] **AC-87** Sensitive operations (Trainer creation, impersonation, deletion, coach-availability override) are audit-logged and the log cannot be edited by any role. (Epic data-integrity criteria)
- [ ] **AC-88** Role dashboards load in under 2 seconds, the Users list of 10,000 users loads in under 3 seconds with pagination, profile saves complete in under 1 second, ShareLink registration completes in under 2 seconds, and the platform serves 1,000 concurrent users. (Epic performance targets)
- [ ] **AC-89** All screens meet WCAG 2.1 AA (keyboard navigation, screen-reader support, contrast, visible focus) and work on phone-sized screens. (Epic accessibility/mobile notes)

## Edge cases

| Case | Expected |
|---|---|
| Registering/creating a user with an email already in use (any letter case) | Refused with "email already registered"; no account, no association created. |
| Visitor opens a ShareLink with an unknown code | Generic "link not valid" page; nothing is created. |
| Player link of a deactivated or deleted Trainer | "Link not valid" page; no association. |
| Coach link used a second time | Refused as already used; Trainer sees status Accepted. |
| Coach link opened after 7 days | Refused as expired; Trainer may resend. |
| Invited Coach already active under another Trainer | Refused with a clear message; the invitation is not accepted. |
| Coach email invited to a different Trainer while a prior invite is still Pending | Both invites may exist; only the first accepted makes the Coach active (the second is then refused). |
| Logged-in player opens a link for a Trainer they already belong to | No duplicate; they are taken to that Trainer's events with a notice they are already associated. |
| Player opens a Trainer link where the association was previously removed by a parent | Treated as a new request; parent-removed children are not re-added without a parent choice. |
| Parent with no children opens a new Trainer's link | No family checklist; the parent alone is associated. |
| Parent unticks everyone in the family checklist | Nothing is associated; the user is told at least one person must be chosen or they can cancel. |
| Child age outside the allowed range | Refused, message names the allowed range. |
| Child profile similar to an existing one | Warning shown; parent can confirm to create anyway. |
| Parent removes a child from a Trainer with upcoming RSVPs | Confirmation first; on confirm RSVPs are cancelled and history kept. |
| Child-login user opens a Trainer link | Blocked message to child, notification email to the parent, no association. |
| Child attempts a forbidden action by direct address (e.g. changing trainer associations) | Refused as forbidden; no change made. |
| Parent approves a request after it expired | Refused with "request expired"; the child is not registered. |
| Parent approves a request twice (double click or two devices) | Only the first decision counts; second is refused with current status. |
| Parent changes the token-approval setting while a request is pending | Pending request keeps the rule in force when it was created; the new setting applies to later requests. |
| Payment hand-off fails after approval | Child is not registered; parent and child are told; the request is recorded as failed, not approved-and-paid. |
| Parent account deactivated or deleted while child requests are pending | Pending requests are auto-denied; the child's login is blocked until a guardian exists (Q-01.14). |
| Password reset requested for an unknown email | The same neutral "if the address exists, an email was sent" message is shown; no account existence is revealed. |
| Password reset link used twice or after 1 hour | Refused; user can request a new link; old links stop working when a new one is issued. |
| New password reset requested while an earlier link is still valid | The earlier link is invalidated. |
| Password reset completed | All other active sessions of that user end. |
| Verification link used after 24 hours | Refused; user can request a new verification email. |
| Login attempts exceed the rate limit | Further attempts refused with a "try again later" message even if the password is correct. |
| Session timeout while filling a form | User is sent to login and returns to the page afterwards; unsaved input is not silently accepted. |
| Trainer tries to open another Trainer's user, player or coach by address | Refused as not found/forbidden; no data returned. |
| Deactivated user has a valid session | Session ends at the next request; login refused with "Account deactivated. Contact support." |
| Super Admin deactivates or deletes their own account | Refused; an admin cannot remove themselves. |
| Super Admin deactivates/deletes the last active Super Admin | Refused. |
| Deleting a Trainer who has active coaches | Refused until the Super Admin reassigns or deactivates those coaches (Q-01.15). Players do not block deletion: their associations end, history is kept, and they may belong to other Trainers. *(Amended 2026-11-12 by the user to match Q-01.15.)* |
| Deleting a user, then someone registers with the original email | Allowed; the anonymized placeholder email is not the original, so the original email is free to register again as a new person. |
| Impersonating a deactivated or deleted user | Deleted users cannot be impersonated; deactivated users can be viewed for support but not logged in as (Q-01.16). |
| Impersonation session reaches 1 hour | Session ends, user returned to Super Admin view with a notice; the log records the end as automatic. |
| Impersonating Super Admin tries to act as another Super Admin or start a nested impersonation | Refused. |
| Super Admin closes the browser while impersonating | Impersonation ends with the session and is logged with the end time. |
| Coach availability: end time before start time, or overlapping slots on the same day | Refused with a message naming the problem. |
| Override reason empty or only spaces | Override refused; assignment stays unconfirmed. |
| Coach availability changed after an assignment was made | Existing assignments are untouched; no retroactive warning (Q-01.06 decides notification). |
| Player availability with no entries at all | Treated as "no preference given"; filters do not exclude them as unavailable. |
| Logo over 2 MB, wrong type, or corrupted file | Refused; previous logo remains in place. |
| Invalid hex color | Refused; previous color remains. |
| Two admins edit the same user at once | The later save is refused with a notice that the record changed; nothing is silently overwritten. |
| Photo upload fails midway | Profile text changes are not lost; the photo field is left as before with an error. |
| Camp-form pre-fill data contains an email that already has an account | User is asked to log in instead; no duplicate account; pre-fill data is attached only after login. |
| Camp-form pre-fill link is tampered with or expired | Treated as an ordinary registration with a blank form. |

## Out of scope

Phase 2 list from the epic (not specified here, not to be built):
- Open Gym / League Instructor role
- Referee / Contractor role
- Social login (Google, Facebook, Apple)
- Two-factor authentication
- Advanced per-user permission customization
- Coaches Corner (messaging hub)
- Build-a-Bag (skill focus tool)
- Feedback tool (player feedback system); AC-46 only lets a child submit a feedback request as an entry point
- Custom role creation (full User Role Editor)
- Advanced portal branding (fonts, full layout, light/dark logos)
- User bulk import/export
- API access for external integrations

Owned by other epics:
- Actual payment processing, tokens, token ledgers, payment methods, Stripe identifiers, subscription status and platform fees (Epic-05). This spec covers only the child-purchase approval request workflow and its hand-off point (AC-38 to AC-45).
- Event creation, scheduling, rosters, RSVP rules (Epic-02). Coach assignment and conflict override are specified here only against an "event" reference (AC-65 to AC-67); no event is created in this epic.
- Camp/evaluation forms and the camp-to-user conversion logic and prompt (Epic-08). This spec covers only the pre-filled registration entry point (AC-85).
- ShareLink usage analytics reporting (Epic-06; this epic only records usage per AC-23), content (Epic-04), CRM (Epic-03), Super Admin tools beyond the Users tool and impersonation history (Epic-07).
- Skill-level definitions and age-group definitions (open client questions; skill level is only a read-only field for players).
- Technical choices (stack, storage, email provider, session mechanism): decided later in architecture.

## Open questions

**Resolved 2026-11-12 by the user: every recommended default below is accepted as the requirement.** Explicitly confirmed: Q-01.03 (all players under 18 are parent-managed child profiles), Q-01.13 (GDPR deletion keeps an audit entry only — no original email, no PII backup). Delivery scope confirmed: server-rendered UI for the epic's key screens plus backend.

**AC-88 decision (2026-11-12, user):** accepted as measured - per-request targets met (see docs/performance-epic-01.md); latency at 1,000 concurrent users (p95 ~5.5 s on the shared dev machine, 0 errors) is deferred to measurement in a production-like environment.

| ID | Question | Recommended default |
|---|---|---|
| Q-01.01 | Skill level definitions (epic Q-01.01). | Beginner, Intermediate, Advanced, Elite as a fixed list set by Trainers; revisit when Epic-03 needs it. Not blocking Epic-01. |
| Q-01.02 | Age group definition (epic Q-01.02). | Store a birth date, derive age and any group from it; group rules left to Epic-02/03. Not blocking. |
| Q-01.03 | Under-18 rule. Epic contradiction: US-01.06 asks whether all under-18s need parent accounts or whether 16-18 may be independent (the epic labels it "Q-01.05", colliding with the real Q-01.05; the epic table has no Q-01.03), while Business Rules state "ALL players under 18 require parent-managed accounts". US-01.03's age range 1-18 also overlaps with "adults use own accounts". | Follow the Business Rule: every player under 18 is a child profile under a parent account; 18 and over registers independently. Child ages 0-17. Needs client and legal (COPPA/GDPR) confirmation. |
| Q-01.04 | Which automated emails are required (epic Q-01.04). | The set in AC-86. |
| Q-01.05 | Email verification: required before login or optional (epic Q-01.05). | Required before login for self-registered users; Trainers created by Super Admin count as verified once they set their password from the emailed setup link. |
| Q-01.06 | Should a Coach be notified when their availability is overridden (epic Q-01.06). | Yes, a notification of the assignment (they already see it); no approval required. |
| Q-01.07 | Session timeout (epic Q-01.07). | 7 days of inactivity ends a session; impersonation fixed at 1 hour per epic. Client to choose among 1, 7, 30 days. |
| Q-01.08 | Trainer setup: temporary password or setup link (US-01.01 says "OR"). | Setup link; no password sent by email. |
| Q-01.09 | Child RSVP/cancel "requires parent approval" (US-01.06) is not defined elsewhere, and US-01.06 cross-references the wrong story ("see US-01.04"; approval is US-01.05). Is free-event RSVP gated, and does the 48-hour flow apply? | Reuse the same approval request, notification and 48-hour expiry for any child RSVP or cancel, free events included. |
| Q-01.10 | Login rate-limit numbers. | 5 failed attempts per email and address in 15 minutes, then a 15-minute lockout. |
| Q-01.11 | Email change ("separate flow" in US-01.11 is undefined). | Not self-service in this release; Super Admin edits email on request. |
| Q-01.12 | Profile photo limits (type, size). | Same as logo: PNG/JPG, max 2 MB. |
| Q-01.13 | What original data may be kept after deletion. US-01.13 anonymizes the user, but the data section lists "original email" and "backup of original data", which conflicts with erasure. | Keep only the audit entry (original user ID, deleter, date, reason); no original email, no data backup. Needs legal sign-off. |
| Q-01.14 | What happens to children when their parent is deactivated or deleted. | Children are deactivated with the parent; history is kept. **When the parent is deleted (GDPR), child profiles and child logins are anonymized too and their Trainer associations end, history kept as "Deleted User" (user decision 2026-11-12, review F-03).** |
| Q-01.15 | What happens to a Trainer's coaches and players when the Trainer is deactivated or deleted. | Deactivated Trainer's organization is blocked; deletion refused while active coaches exist until the Super Admin reassigns or deactivates them. |
| Q-01.16 | May a Super Admin impersonate deactivated users. | Yes, for support; deleted users never. |
| Q-01.17 | Epic inconsistencies to confirm: branding is labelled "US-01.12" in the Out-of-scope note and footer but is US-01.14; story count says 12 but there are 14; section numbers 10-12 appear twice. | Treat the real IDs US-01.01..US-01.14 as authoritative. |
| Q-01.18 | Can two guardians share one child. | One parent account per child in this release; co-guardians out of scope. |
