# Company team roles and superadmin access

Implemented 5 November 2026. User explicitly authorized isolated implementation and main publication while the shared board is unavailable, including an exception to its file and git reservation requirement.

`CompanyTeamAccess` resolves team authority from the target company: platform superadmin/master override, company ownership, then an active membership and its `company_role_id`. A company role update never changes `users.role_id`. Ownership cannot be reassigned through team APIs. Platform/account roles and the existing global feature matrix remain separate from company team responsibilities.

Accounting/CRM/OPS actions still require explicit company-scoped `accounting_permissions` abilities. A Finance role, department or Road subdepartment never implicitly grants those abilities. Superadmin/master can list companies and access accounting/OPS without a membership or permission row; owned and actively connected companies sort before the rest. Normal users still see only owned/active companies, and inactive membership blocks accounting access even if an old permission row remains.

Organization fields on `company_user`:

- `company_role_id`: role within this company, limited to the assignable team role set.
- `business_departments`: management, sales, operations, customs, warehouse, finance, procurement.
- `subdepartments`: optional road, air, sea, rail, multimodal, only alongside Operations.
- `departments`: retained for older clients. Legacy accounting maps to Finance; transport modes imply Operations. This is organization and work filtering, not a new data authorization grant.

The additive `2026_11_05_000001_scope_company_team_roles` migration snapshots existing assignable account roles into memberships and maps organization values. It does not create users, seed records, reset databases, touch invoices or grant accounting abilities. `PrepareOpsRelease` includes this finite migration and rejects unregistered partial schema. A rollback must retain membership data rather than dropping its columns. Deployment must use the existing verified database preflight; no generic migrate/seed/reset command is needed.

Until the migration is installed, read paths retain legacy organization compatibility and superadmin access works; team mutations return 503 instead of changing the global user role.

Team list/show/mutation endpoints check company scope. Destruction deactivates a membership and retains its history. Invite creation and user lookup require company team management. Invite acceptance checks the authenticated email, expiry, role, inviter's current authority and existing membership; it assigns the company role only and is idempotent for the already accepted invitation. It never grants accounting permissions. No email is sent by this implementation.

Frontend team management uses `/accounting/companies` and server-provided `membership_role`/`can_manage` rather than the global user role. Company switching resets OPS forms and ignores stale overview responses. The global module matrix shown in the team page is explicitly a platform reference, not a company grant.

Financial UI consolidation, invoice/payment/POS workflow changes and new closing rules are excluded. See the Qla/Keno review proposal for future phases 2–4; they await review.
