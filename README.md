<p align="center">
  <img src="docs/assets/proximify_packman.svg" width="250px" alt="proximify packman plugin icon">
</p>

# Packman

This Composer plugin creates one package manager per project and serves private packages to Composer from a local web server. It assumes that all private packages have the same namespace and are hosted at the same web service with a common URL prefix (e.g. `https://github.com/CompanyName/...`).

## How it works

The plugin reads the `composer.json` of the root project looks for packages listed under `require` and `require-dev` whose namespace is equal to the root project's. Such packages are assumed to be private and are served from a **local package manager** using a composer repository (public ones will still be found by Composer at packagist.org before their local versions).

### Steps

1. Read the `composer.json` of the root project and search for requirements in the same namespace than the root project. Also consider the new packages being required via the CLI.
2. Create a [Satis](https://composer.github.io/satis/) repository in the sub-folder **private_packages** with the selected packages.
3. Start a PHP built-in web server on the **private_packages/repos** folder at `localhost:8081` for the duration of any Composer command, and close it when done.
4. Tell Composer to also look for repositories in the temporary local packaging server at `localhost:8081`.

## Getting started

### Method 1: Global install

Add the plugin to the global composer (usually located at `~/.composer`)

```bash
$ composer global require proximify/packman
```

This option is the best because it has to be done only once and it works for installing existing projects that come with private packages.

### Method 2: Per-project install

Add the plugin to the development dependencies of your project

```bash
$ composer require proximify/packman --dev
```

The per-project option does not work when installing existing projects with private dependencies in them because Packman will not exist until after the installation finishes. The packman method works with new projects because it can be installed before the private packages. One manual solution is to remove the private dependencies from a project, install it, and then put them back. Such a manual solutions solves the first-install problem.

### Next step

There is nothing else to do if the default parameter values are appropriate. Moreover, the folder `private_packages` is added to `.gitignore` automatically, so that's also taken care of 🥳.

> **Important:** Packman assumes that the standard ssh credentials required to fetch the needed repositories have been set up already.

## Use Symlink for dev

When developing multiple interdependent components at the same time, it is better to use symlink repositories than private ones fetched with <img src="docs/assets/proximify_packman.svg" width="25px" alt="packman icon" style="vertical-align:middle">. The reason for that is that the packages won't need to be updated via composer every time they change. You just modify a project and the change is "applied" to the copy of the package within the vendor folder of another project.

It's a good idea to define your symlink repositories in the **global** composer settings. In that way, you don't have to remember to remove the repository specs from each local `composer.json` that needs it.

```json
// Example global configuration of a symlink repository
// ~/.composer/composer.json
{
    "minimum-stability": "dev",
    "require": {
        "my-org/my-repo": "*"
    },
    "repositories": [
        {
            "type": "path",
            "url": "~/my-org/my-repo",
            "options": {
                "symlink": true
            }
        }
    ]
}
```
The variables here are: `my-org`, `my-repo`, and the value of `url`. Everything else stays as shown.

> Use a relative path for the "url" that is common to all members of your team. You can either start from your home directory with `~` or you can make it relative to the root project with `../`. The `~` is convenient for packages installed globally. The `../` allows for more freedom as long as the package is not installed globally or that the relative path also works from the global `.composer` folder.

It is fine and normal to have both symlink repositories and private once managed with <img src="docs/assets/proximify_packman.svg" width="25px" alt="packman icon" style="vertical-align:middle">. The symlink repositories will have higher precedence than the private ones.

Once the Packman plugin is installed to fetch remote private packages, and the global and/or local `composer.json` is configured for any additional local symlink repositories, you can run

```bash
composer require my-org/my-repo:dev-master
```

to get the package defined as the latest development commit of the master branch of the given repository.

### Deployment to Prod

It's not a good idea to use symlink repositories to deploy to a production machine. The symlink repositories are only meant for local development. In contrast, Packman can be used to deploy to a production server (e.g. as a zip bundle). But, if the intent is to fetch the packages on the production server, then the default Packman solution of hosting private packages on `localhost` won't work if that URL endpoint is not authenticated in some way. In other words, **Packman is for local machines only**. If used on a server, the Packman URL (`localUrl`) should only be accessible **only** from the local machine.

## Options

The assumption that private packages have the same namespace than that of the root project might not be correct. The namespace to use for private packages can be set via a custom parameter.

Custom parameter values are set in the `composer.json` under the `extras` property. For example,

```json
// composer.json
{
    "name": "my-namespace/my-composer-project",
    "extras": {
        "packman": {
            "namespace": "alt-my-namespace",
            "localUrl": "http://localhost:8081",
            "remoteUrl": "https://github.com"
        }
    }
}
```

If the protocol of the **localUrl** is http instead of https, **packman** will set the `secure-http` to `false` (see [secure-http](https://getcomposer.org/doc/06-config.md#secure-http)). Othewrise, Composer will refuse to contact a URL like `http://localhost:8081`.

### Parameters

The paremeters are set in the global and/or local composer.json files under the keys `extras: {packman: { ... }}`.

| Parameter | Default value                         | Description                                                                                                                           |
| --------- | ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| namespace | `{ROOT-NAMESPACE}`                    | The namespace of the private packages to manage.                                                                                      |
| remoteUrl | `https://github.com/{ROOT-NAMESPACE}` | Base URL of the repository hosting servers where the private repositories are located.                                                |
| localUrl  | `http://localhost:8081`               | The URL to be used for the local server of Composer packages.                                                                         |
| storeDir  | `./private_packages`                  | The folder where to used by Packman to store the packages. It can be local to each project (default), or shared by multiple projects. |

## Commands

<img src="docs/assets/proximify_packman.svg" width="25px" alt="packman icon" style="vertical-align:middle"> runs automatically when composer runs, and, in general, it does the right things at the the right time. However, it is also possible to run commands on demand using `composer COMMAND` for local ones and `composer global COMMAND` for commands that apply to the global composer settings (e.g. `packman:config`).

| Parameter      | Description                                                                                                                   |
| -------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| packman:config | Save the values for Packman parameters. Use `composer global packman:config` to save to the global `.composer/composer.json`. |  |
| packman:update | Perform an update of all registered packages.                                                                                 |
| packman:reset  | Reset the entire package store.                                                                                               |
| packman:start  | Start the local web server that servers the packages to composer.                                                             |
| packman:stop   | Stop the local web server that servers the packages to composer.                                                              |


## About Satis

This plugin uses the [Satis](https://composer.github.io/satis/) repository generator (see [using satis](<[How-to](https://composer.github.io/satis/using)>)).

---

## Contributing

This project welcomes contributions and suggestions.

## License

Copyright (c) Proximify Inc. All rights reserved.

Licensed under the [MIT](https://opensource.org/licenses/MIT) license.

**Software component** is made by [Proximify](https://proximify.com). We invite the community to participate.
