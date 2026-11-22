<?php

/**
 * Club role permissions.
 *
 * `capabilities` is the default matrix shown on the club settings screen and
 * used when a club has not customised its own. A club may override the roles
 * for any capability; overrides live in club.settings.permission_matrix.
 *
 * `route_map` maps the first segment after /{slug}/admin/ to the
 * capability that guards it. Prefixes absent from this map fall back to
 * requiring any staff role.
 */
return [

    'capabilities' => [
        'view_dashboard' => [
            'label' => 'Dashboard',
            'description' => 'Access analytics dashboard, revenue reports, and attendance statistics',
            'roles' => ['owner', 'admin', 'treasurer'],
        ],
        'manage_meetings' => [
            'label' => 'Meetings & Summonses',
            'description' => 'Create, edit, generate recurring rules, and publish summonses for lodge/club meetings',
            // The screens call the coach role "Secretary"; 'secretary' is kept for any older memberships that hold it.
            'roles' => ['owner', 'admin', 'coach', 'secretary'],
        ],
        'manage_events' => [
            'label' => 'Events',
            'description' => 'Create, edit, and publish social events, ticketing tiers, dining options, and see who has booked and paid',
            'roles' => ['owner', 'admin', 'coach', 'treasurer'],
        ],
        'manage_subscriptions' => [
            'label' => 'Subscriptions',
            'description' => 'Configure membership dues tiers, pricing, and recurring subscription plans',
            'roles' => ['owner', 'admin', 'treasurer'],
        ],
        'manage_members' => [
            'label' => 'Members',
            'description' => 'View member roster, approve new join applications, and manage member roles',
            'roles' => ['owner', 'admin', 'coach', 'secretary'],
        ],
        'manage_billing' => [
            'label' => 'Billing',
            'description' => 'Access payment history, issue receipts, and manage club platform billing',
            'roles' => ['owner', 'admin', 'treasurer'],
        ],
        'view_accounting' => [
            'label' => 'View Accounting (read-only)',
            'description' => 'View the accounting records, ledgers and reports without being able to change them',
            'roles' => ['owner', 'admin', 'treasurer', 'examiner'],
        ],
        'manage_communications' => [
            'label' => 'Communications',
            'description' => 'Create and publish community announcements, posts, and news updates',
            'roles' => ['owner', 'admin', 'coach'],
        ],
        'send_newsletters' => [
            'label' => 'Newsletters',
            'description' => 'Draft, preview, and dispatch bulk email newsletters to members',
            'roles' => ['owner', 'admin', 'coach'],
        ],
        'pass_on_communications' => [
            'label' => 'Passing on communications',
            'description' => 'Pass on summonses and notices from sister and reciprocal lodges, and Provincial and Grand Lodge communications, to members who want them',
            'roles' => ['owner', 'admin', 'secretary'],
        ],
        'edit_website' => [
            'label' => 'Website Builder',
            'description' => 'Build CMS pages, customize navigation, and update site content',
            'roles' => ['owner', 'admin'],
        ],
        'manage_webhooks' => [
            'label' => 'Webhooks',
            'description' => 'Send the club\'s events (members, meetings, bookings, payments) to other systems',
            'roles' => ['owner', 'admin'],
        ],
        'view_activity' => [
            'label' => 'Activity',
            'description' => 'See who joined, left, or had their role or status changed, and when',
            'roles' => ['owner', 'admin'],
        ],
        'manage_hall' => [
            'label' => 'Hall details',
            'description' => 'Edit the hall\'s address, contacts, facilities and the lodges that meet there',
            // A hall's Secretary has the coach role (the screens call it "Secretary").
            'roles' => ['owner', 'admin', 'coach', 'secretary'],
        ],
        'manage_board' => [
            'label' => 'Hall board',
            'description' => 'Invite and remove the people on the hall\'s board and set their offices',
            'roles' => ['owner', 'admin'],
        ],
        'manage_sections' => [
            'label' => 'Order sections',
            'description' => 'Create and edit the province\'s sections for each order and the people who run them',
            'roles' => ['owner', 'admin'],
        ],
        'manage_provincial_officers' => [
            'label' => 'Provincial officers',
            'description' => 'Keep the list of the province\'s officers and their appointments',
            'roles' => ['owner', 'admin', 'coach'],
        ],
        'manage_settings' => [
            'label' => 'Club Settings',
            'description' => 'Configure organization profile, branding, custom domain, and permission matrix',
            'roles' => ['owner', 'admin'],
        ],
    ],

    'route_map' => [
        '' => 'view_dashboard',
        'analytics' => 'view_dashboard',

        'accounting' => 'manage_billing',
        'bank-accounts' => 'manage_billing',
        'bank-imports' => 'manage_billing',
        'bank-reconciliation' => 'manage_billing',
        'reconciliation-workspace' => 'manage_billing',
        'billing' => 'manage_billing',
        'payment-options' => 'manage_billing',
        'charity' => 'manage_billing',

        'subscriptions' => 'manage_subscriptions',
        'dues-subscriptions' => 'manage_subscriptions',

        'meetings' => 'manage_meetings',
        'officers' => 'manage_meetings',
        'committee' => 'manage_meetings',
        'visitors' => 'manage_meetings',

        'members' => 'manage_members',
        'users' => 'manage_members',
        'candidates' => 'manage_members',

        'events' => 'manage_events',

        'newsletters' => 'send_newsletters',
        'notices' => 'send_newsletters',

        'email-templates' => 'manage_communications',
        'posts' => 'manage_communications',
        'updates' => 'manage_communications',

        'pages' => 'edit_website',
        'media' => 'edit_website',
        'media-manager' => 'edit_website',

        'activity' => 'view_activity',
        'webhooks' => 'manage_webhooks',

        'settings' => 'manage_settings',

        'hall' => 'manage_hall',
        'board' => 'manage_board',
        // Every board role can see which lodges meet at the hall; manage_events is held by all four by default.
        'lodges' => 'manage_events',

        'sections' => 'manage_sections',
        'provincial-officers' => 'manage_provincial_officers',
        'chapters' => 'manage_events',
        'circulars' => 'manage_events',
    ],

    /**
     * Sections of /{slug}/admin that a group (a hall, province group or province section, keyed by club type code)
     * does not have: they answer 404 whoever asks. A province group also has no events, newsletters or accounts (every
     * manage_billing, view_accounting and send_newsletters segment, except billing: that is the platform subscription page).
     */
    'group_hidden_sections' => [
        'hall' => $groupSections = ['members', 'users', 'candidates', 'meetings', 'officers', 'committee', 'visitors', 'subscriptions', 'dues-subscriptions', 'charity'],
        'province_section' => $groupSections,
        'province_group' => [...$groupSections, 'events', 'newsletters', 'notices', 'accounting', 'bank-accounts', 'bank-imports', 'bank-reconciliation', 'reconciliation-workspace', 'payment-options', 'pass-on'],
    ],

    /**
     * Capabilities a group has no use for (they guard only hidden sections, or lodge-to-lodge communications), keyed by
     * club type code, so its Roles and permissions screen leaves them out. manage_events stays on a province group:
     * its chapters and circulars screens use it.
     */
    'group_hidden_capabilities' => [
        'hall' => $hallCapabilities = ['manage_meetings', 'manage_members', 'manage_subscriptions', 'pass_on_communications', 'manage_sections', 'manage_provincial_officers'],
        'province_section' => [...$hallCapabilities, 'manage_hall'],
        'province_group' => ['manage_meetings', 'manage_members', 'manage_subscriptions', 'pass_on_communications', 'manage_hall', 'manage_billing', 'view_accounting', 'send_newsletters'],
    ],

    /**
     * Capabilities that only guard group sections, so a lodge's Roles and permissions screen leaves them out.
     */
    'lodge_hidden_capabilities' => ['manage_hall', 'manage_board', 'manage_sections', 'manage_provincial_officers'],

    /**
     * Always permitted, regardless of the club's matrix. Without this a club
     * could edit its own matrix in a way that locked its owner out for good.
     */
    'always_allowed_roles' => ['owner'],

];
