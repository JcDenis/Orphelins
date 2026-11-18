<?php

/**
 * @file
 * @brief       The plugin Orphelins definition
 * @ingroup     Orphelins
 *
 * @defgroup    Orphelins Plugin Orphelins.
 *
 * Find dead links, missing files and unused media of a blog.
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
declare(strict_types=1);

if (!isset($this) || !is_object($this) || !method_exists($this, 'registerModule') || !isset($this->id) || !is_string($this->id)) {
    return;
}

$this->registerModule(
    'Dead links and unused media',
    'Find dead links, missing files and unused media of the blog',
    'Jean-Christian Paul Denis and Contributors',
    '0.3',
    [
        'requires' => [
            ['php', '8.3'],
            ['core', '2.40'],
        ],
        'permissions' => 'My',
        'type'        => 'plugin',
        'support'     => 'https://github.com/JcDenis/' . $this->id . '/issues',
        'details'     => 'https://github.com/JcDenis/' . $this->id . '/blob/master/README.md',
        'repository'  => 'https://raw.githubusercontent.com/JcDenis/' . $this->id . '/master/dcstore.xml',
        'date'        => '2026-11-18T20:45:00+00:00',
    ]
);
