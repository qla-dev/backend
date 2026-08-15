# Smartfreight relational model

Core relationship chain:

```text
roles -> users <-> companies
              |-> customers
              \-> driver_profiles
companies/users -> vehicles -> vehicle_locations
users/companies/drivers/vehicles -> loads -> load_stops
                                      |-> offers
                                      |-> shipments -> tracking_events
                                      |-> routes -> route_stops
                                      |-> load_notes / documents
companies/loads/users -> conversations -> messages
users/companies/loads -> invoices -> invoice_items
users -> email_templates -> email_campaigns -> recipients
companies/roles/users -> company_invitations
```

All arrows represented by `*_id` in business tables are database-level foreign keys. Pivot tables (`company_user`, `fleet_access`, `conversation_user`) also use constrained foreign keys and composite uniqueness rules.

API access is authenticated with Sanctum. Superadmin-only registries (roles, all users, all companies, and email campaigns), company team/fleet administration, and finance resources are protected by role middleware.
