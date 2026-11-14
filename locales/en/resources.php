<?php

/**
 * @file
 * @brief       The plugin Orphelins help resource
 * @ingroup     Orphelins
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
declare(strict_types=1);

\Dotclear\App::backend()->resources()
    ->set('help', 'Orphelins', __DIR__ . '/help/help.html')
    ->set('help', 'Orphelins-check', __DIR__ . '/help/check.html');
