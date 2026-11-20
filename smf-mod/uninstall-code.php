<?php
/**
 * AR License Manager - SMF package uninstall code.
 * Removes every arlm_* setting the mod created. Forum members, posts,
 * boards and all other SMF data are untouched.
 * Copyright (c) ARNIT CREATION.
 */

global $smcFunc;

$smcFunc['db_query'](
    '',
    'DELETE FROM {db_prefix}settings
     WHERE variable LIKE {string:arlm_pattern}',
    array(
        'arlm_pattern' => 'arlm\_%',
    )
);
