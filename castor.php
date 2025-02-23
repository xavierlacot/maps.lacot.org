<?php

use Castor\Attribute\AsTask;

use function Castor\guard_min_version;
use function Castor\import;
use function Castor\io;
use function Castor\notify;
use function Castor\variable;
use function docker\about;
use function docker\build;
use function docker\docker_compose_run;
use function docker\generate_certificates;
use function docker\up;

// use function docker\workers_start;
// use function docker\workers_stop;

guard_min_version('0.18.0');

import(__DIR__ . '/.castor');

/**
 * @return array{project_name: string, root_domain: string, extra_domains: string[], php_version: string}
 */
function create_default_variables(): array
{
    $projectName = 'cartos';
    $tld = 'test';

    return [
        'project_name' => $projectName,
        'root_domain' => "{$projectName}.{$tld}",
        'extra_domains' => [
            "www.{$projectName}.{$tld}",
        ],
        'php_version' => $_SERVER['DS_PHP_VERSION'] ?? '8.3',
        'maps_data_folder' => __DIR__ . '/maps',
    ];
}

#[AsTask(description: 'Builds and starts the infrastructure, then install the application (composer, yarn, ...)')]
function start(): void
{
    io()->title('Starting the stack');

    // workers_stop();
    generate_certificates(force: false);
    build();
    up(profiles: ['default']); // We can't start worker now, they are not installed
    install();
    // workers_start();

    notify('The stack is now up and running.');
    io()->success('The stack is now up and running.');

    about();
}

#[AsTask(description: 'Installs the application (composer, yarn, ...)', namespace: 'app', aliases: ['install'])]
function install(): void
{
    io()->title('Installing the application');

    $basePath = sprintf('%s/application', variable('root_dir'));

    if (is_file("{$basePath}/composer.json")) {
        io()->section('Installing PHP dependencies');
        docker_compose_run('composer install -n --prefer-dist --optimize-autoloader');
    }
    if (is_file("{$basePath}/yarn.lock")) {
        io()->section('Installing Node.js dependencies');
        docker_compose_run('yarn install --frozen-lockfile');
    } elseif (is_file("{$basePath}/package.json")) {
        io()->section('Installing Node.js dependencies');

        if (is_file("{$basePath}/package-lock.json")) {
            docker_compose_run('npm ci');
        } else {
            docker_compose_run('npm install');
        }
    }
    if (is_file("{$basePath}/importmap.php")) {
        io()->section('Installing importmap');
        docker_compose_run('bin/console importmap:install');
    }

    qa\install();
}
