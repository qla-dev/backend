# Smartfreight relational model

Core relationship chain:

```text
roles -> users <-> companies
              |-> customers
              \-> drivers
companies/users -> vehicles -> vehicle_locations
users/companies/drivers/vehicles -> loads -> load_stops
customers ------------------------> loads (consignee_customer_id)
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

API access is authenticated with Sanctum. Superadmin-only registries (roles, all users, all logistics companies, and email campaigns), company team/fleet administration, and finance resources are protected by role middleware.

The authenticated `customer-options` endpoint exposes the safe global-recipient option shape used by remote selectors. It supports `search`, `limit`, and `pageno`, and returns `meta.has_more` for Select2-style incremental loading. A load's creator remains `customer_user_id`; its selected recipient is stored independently as nullable `consignee_customer_id`, so imported customers without login accounts can be selected.
