<?php

return [

    'title' => 'Offers',
    // The app bar's title, in the app's own currency: "Earn Coins". The
    // wall wears the publisher's brand, so the first words on it are theirs.
    'earn' => 'Earn :currency',
    'search' => 'Search offers',
    'search_placeholder' => 'Search...',
    'clear' => 'Clear search',
    'filter' => [
        'device' => 'Filter by device',
        'all_devices' => 'All devices',
    ],
    'all' => 'All',
    'all_offers' => 'All Offers',
    'featured_offers' => 'Featured Offers',
    'rail_next' => 'More featured offers',
    'reward' => 'Reward',
    'test_badge' => 'Test offer',
    'featured' => 'Featured',
    // One word, because it is a chip on a card and a sentence there wraps into
    // a grey blob. The detail view has room to say it properly.
    'multi_reward' => 'Repeatable',
    // Beats "pays more than once" wherever we know the number: "4 steps" tells
    // the user how much work it is, which is the thing they are deciding.
    'steps' => '{1} :count step|[2,*] :count steps',
    'more' => 'Show more offers',
    'skip' => 'Skip to offers',
    // Sits above the figure on a multi-step offer: the steps pay separately,
    // so the headline is a ceiling, not a promise.
    'up_to' => 'Up to',
    // The count is rendered separately as a `.num` figure, so this is the word
    // beside it and never the number itself.
    'people' => '{1} person|[2,*] people',
    'powered_by' => 'Powered by',
    'too_many' => 'Too many requests. Please try again in a minute.',

    // Names the actual cause. "No offers available in your country right now"
    // beats a shrug illustration — docs/03 §5.
    'empty' => [
        'title' => 'No offers right now',
        'body' => 'There are no offers available in your country and on your device right now. Please check back later.',
    ],

    // The other empty: we could not establish the country or the device, so we
    // show nothing rather than guess. Naming a country here would be a lie —
    // the country is exactly what is missing.
    'unplaced' => [
        'title' => 'We could not confirm your device',
        'body' => 'Every offer is limited to certain countries and devices, and we could not confirm yours. Turn off any VPN or proxy and reload, or try again from a different connection.',
    ],

    // A different state from `empty`: nothing is missing from the wall, the
    // search or the category is just too narrow, so this one offers a way out.
    'no_results' => [
        'title' => 'No offers match that',
        'body' => 'Try a shorter word, another device, or clear the filters to see everything available to you.',
        'reset' => 'Show all offers',
    ],

    // The detail view — what the offer asks of the user before they leave for
    // it. Plain words: "steps", not "goals" or "events", which are our word for
    // it and the network's, never the user's.
    'offer' => [
        'steps' => 'What you have to do',
        'single_step' => 'Complete the offer',
        'steps_note' => 'Complete every step to earn the full :total.',
        'how' => 'Before you start',
        'requirements' => 'Who this is for',
        'devices' => 'Devices',
        'any_device' => 'Any device',
        'countries' => 'Countries',
        'worldwide' => 'Worldwide',
        'os' => 'Minimum OS',
        'start' => 'Start this offer',
        'back' => 'All offers',
        'terms' => 'Opens in a new tab. Rewards are credited after the partner confirms your progress.',
        'close' => 'Close',
        'loading' => 'Loading offer',

        // Static, and true of every offer on the wall. Each one is a real
        // support ticket, which is why they are on the screen the user reads
        // before they leave rather than in a help page nobody opens.
        'notes' => 'Important',
        'note' => [
            'vpn' => 'Turn off any VPN or proxy. Offers completed through one are not credited.',
            'device' => 'Finish on the same device you started on.',
            'first_time' => 'Most offers pay for first-time completions only.',
            'credit' => 'Rewards usually arrive within 24–48 hours.',
        ],
    ],

    'sort' => [
        'label' => 'Sort offers',
        'featured' => 'Featured',
        'reward' => 'Top reward',
        'trending' => 'Trending',
        // Only ever seen with JavaScript off, where the select cannot submit
        // itself. It says what it does rather than naming the list again.
        'apply' => 'Apply',
    ],

    // The user's own clicks. Statuses are text and never colour: a list of
    // coloured "Credited" pills would spend the one signal this screen
    // reserves. The credited amount is the thing in the publisher's colour,
    // because it is the one thing here that is money.
    'history' => [
        'status' => [
            'started' => 'Started',
            'pending' => 'Pending',
            'approved' => 'Credited',
            'rejected' => 'Rejected',
            'reversed' => 'Reversed',
        ],
        'empty' => [
            'title' => 'Nothing here yet',
            'body' => 'Offers you start will appear here, along with what each one earned.',
            'action' => 'Browse offers',
        ],
    ],

    'device' => [
        'android' => 'Android',
        'ios' => 'iOS',
        'desktop' => 'Desktop',
        'other' => 'Other',
    ],

    'notice' => [
        'unavailable' => [
            'title' => 'Offerwall unavailable',
            'body' => 'This offerwall is not available right now. Please contact the app you came from.',
        ],
        // A `userid` or a form field the wall will not accept. Addressed to
        // the end user, who cannot fix it, so it points at the only party who
        // can — the app that built the link.
        'invalid' => [
            'title' => 'Offerwall link not valid',
            'body' => 'This offerwall link is missing or malformed. Please go back and open it again from the app you came from.',
        ],
        'declined' => [
            'title' => 'Offers hidden',
            'body' => 'You declined data processing, so no offers can be shown and no offer can be opened.',
        ],
        'banned' => [
            'title' => 'Offers unavailable',
            'body' => 'Offers are not available for this account. If you believe this is a mistake, contact the app you came from.',
        ],
        'blocked' => [
            'title' => 'Offer unavailable',
            'body' => 'We could not open this offer. Please go back and try a different one.',
        ],
        // The same refusal on the partner tab. The sentence above is
        // offer-shaped and there is no other offer to try there — the way out
        // is the offers tab, so it has to say that instead.
        'partner_blocked' => [
            'title' => 'We could not open this offerwall',
            'body' => 'Please go back and choose an offer instead. If this keeps happening, contact the app you came from.',
        ],
        'offer_gone' => [
            'title' => 'Offer unavailable',
            'body' => 'This offer is no longer available. Please go back and choose another one.',
        ],
        // The one place the fraud engine names what caught someone. It is a
        // deliberate exception to `Decision::publicReason()`: a fraud farm
        // running through a VPN already knows it is running through a VPN, so
        // the sentence teaches them nothing — while a real user does not know
        // their VPN is why the wall is empty, and one toggle makes them a
        // served user. Category only, never the rule, the ASN or the provider.
        'blocked_proxy' => [
            'title' => 'Turn off your VPN to continue',
            'body' => 'You appear to be connecting through a VPN or proxy. Disable it and reload this page to see your offers.',
        ],
        // No user-side fix exists for this one, so it does not pretend there is.
        'blocked_datacenter' => [
            'title' => 'Offers unavailable on this connection',
            'body' => 'Offers cannot be shown on this network. Try again from a mobile or home internet connection.',
        ],
        'blocked_risk' => [
            'title' => 'Offers unavailable on this connection',
            'body' => 'We could not verify this connection. Try again from a mobile or home internet connection.',
        ],
        // Deliberately says nothing. The neighbours above name a category
        // because a real person on a VPN or a corporate network does not know
        // that is why their wall is empty, and telling them is what turns them
        // back into a served user. Nobody reaches an offerwall on an emulator by
        // accident, so there is no honest reader here to help — naming the
        // signal would only tell a farm which string to change.
        'blocked_unavailable' => [
            'title' => 'Offers unavailable',
            'body' => 'We could not show offers here. Please try again later.',
        ],
        // Test mode only. The wording is deliberately about what just happened
        // to the integrator's own system, because that is who is reading it.
        'test_fired' => [
            'title' => 'Test conversion sent',
            'body' => 'A real conversion ran through the full pipeline and your postback has been queued. Check your postback report for the delivery attempt. Test conversions never affect earnings.',
        ],
        'back' => 'Back to offers',
    ],

    'tabs' => [
        // The strip's own name, for the nav landmark. It cannot be `title` —
        // that reads "Offers", and one of the tabs inside the strip is also
        // "Offers", which leaves a screen reader announcing the same word for
        // the container and its contents.
        'label' => 'Offerwall sections',
        'offers' => 'Offers',
        'history' => 'History',
        'partners' => 'Offerwall',
    ],

    // The hamburger sheet. Only destinations that exist: an appearance toggle
    // riding the `?theme=` override, and the operator's own legal pages. A
    // menu entry that leads nowhere is worse than no entry.
    'menu' => [
        'label' => 'Menu',
        'appearance' => 'Appearance',
        'light' => 'Light mode',
        'dark' => 'Dark mode',
        'privacy' => 'Privacy policy',
        'terms' => 'Terms of service',
    ],

    'partner' => [
        'kicker' => 'Partner wall',
        // Partners are never named on the wall (AGENTS.md: network anonymity).
        // A number, in the catalogue's own order, is the whole label.
        'numbered' => 'Offerwall :n',
        'open' => 'Open',
        'back' => 'Back',
        'empty' => [
            'title' => 'No offerwalls yet',
            'body' => 'Partner offerwalls will appear here when one is available.',
            'action' => 'Browse offers',
        ],
    ],

    'consent' => [
        'title' => 'Before you start',
        'intro' => 'To show you offers and pay your reward, we process a small amount of data about this visit.',
        'what_label' => 'What we collect',
        'what' => 'Your IP address, your country, your device type, and the user ID the app sent us.',
        'why_label' => 'Why',
        'why' => 'To prevent fraud and to credit your reward to the right account.',
        'who_label' => 'Who receives it',
        'who' => 'The partner network behind the offer you choose. Nobody else.',
        'accept' => 'Accept',
        'decline' => 'Decline',
        'note' => 'If you decline, no offers are shown and no offer can be opened. Your choice is stored so you are not asked again.',
        'review' => 'Change my answer',
    ],

];
