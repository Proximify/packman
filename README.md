<p align="center">
  <img src="docs/assets/proximify_packman.svg" width="250px" alt="proximify packman plugin icon">
</p>

# Packman

This Composer plugin creates a package manager and serves private packages to Composer from a local web server. By default, it creates one package manager per project within a `packman` folder. A local web server is started and stopped automatically when needed by a composer task (usually `http://localhost:8081`). Packman assumes that all private packages have the same vendor name and that their source is hosted at a common location (e.g. `https://github.com/CompanyName/...`).

## Terminology

In Composer terminology, a **repository** is a set of packages, and a **package** is simply a commit in a repository. A commit can be identified in relative terms by its brach name and its version tag (it it has one). For example, in plain English, version constraints read as "most recent commit in the develop branch" or "package with version 1.0 or higher".

A **private repository** is a set of **private packages**. Packages can be required by other packages in relative terms based on their [semantic version](#semantic-versioning). That is, instead of specifying a commit hash, one can request the newest package that matches a version pattern, such as `1.1.*`.

After running composer `install`, `update` or `require`, the resulting `require` pattern of each package is **locked** to the specific **commit hash** that **satisfies** the requirement.

## How Packman works

Whenever a composer task is run from the CLI, the plugin reads the `composer.json` of the root project and looks for packages listed under `require` and `require-dev`. The packages whose vendor name is equal to that of the root project are considered candidate private packages. The set of candidates is pruned by ignoring the ones publicly available from Packagist. The final set of private packages are downloaded from the their source location and served from a **local web server** acting as a composer-type repository.

### Steps

1. Read the `composer.json` of the root project, search for requirements in the same vendor name than the root project, and add them as _candidate private packages_;
2. Read the Composer CLI command arguments, identify if a private package is being required (e.g. `composer require my-org/my-package`), and if so, add it to the set of candidates;
3. Create the final set of private packages by ignoring the candidates that are already publicly available at Packagist;
4. Create a [Satis](https://composer.github.io/satis/) repository in the sub-folder **packman/repos** with the selected packages;
5. Start a PHP built-in web server on the **packman/repos** folder at the selected URL (usually `localhost:8081`) just for the duration of the active Composer command, and close it when done;
6. Tell Composer to also look for repositories in the temporary local Packman web server.

## Getting started

Packman assumes that the standard ssh credentials required to fetch the needed repositories have been set up already. That's the usual case since you are probably using git to fetch your repositories.

### Method 1: Global install [recommended]

Add the plugin to the global composer (usually located at `~/.composer`)

```bash
$ composer global require proximify/packman
```

The global method is the best because it only needs to be performed once and works on all types of projects.

There is nothing else to do if the default parameter values are appropriate. Moreover, the folder `packman` is added to `.gitignore` automatically, so that's also taken care of 🥳.

> Packman will activate for all composer projects and see if they depend on private packages. If they don't, it won't do anything, so you won't get a `packman` folder in projects that don't need it.

### Method 2: Per-project install

Add the plugin to the development dependencies of your project

```bash
$ composer require proximify/packman --dev
```

The method works well on new projects because Packman can be installed before the private packages. However, when performing a `composer install` on an existing project with private packages, Packman won't yet be available to plugin into Composer and manage them. A possible, clunky solution for such cases is to remove the private dependencies from the project, install Packman, and then put them back. Since that's not super fun, we recommend installing Packman globally.

## Using Symlink repositories

When developing multiple interdependent components at the same time, it is better to use [symlink repositories](https://getcomposer.org/doc/05-repositories.md#path) than private ones fetched with Packman. The reason for that is that the packages won't need to be updated via composer every time they change. You just modify a project and the change is "applied" to the copy of the package within the vendor folder of another project.

Packman can add symlink repositories to composer automatically when a Composer command is run. To enable that feature, the `symlinkDir` option has to be set to an existing directory. When `symlinkDir` is defined,
any repository name listed under `symlinks` will be designated as a path-type repository and symlinked, even if publicly available from Packagist.

Packman adds the **symlink repositories** to the active composer object automatically so there is no need to manually add them to the `repositories` section of a `composer.json`.

```json
// composer.json
{
    "extra": {
        "packman": {
            "symlinkDir": "~/root-of-all-repositories",
            "symlinks": ["repo-name1", "repo-name2"]
        }
    }
}
```

**Note:** If the `symlinkDir` value is set, the committed `composer.json`, it should be given as a relative path (either `~/` or `../`). By doing that, other members of your team will be able to have a similar configuration for them. If you don't expect other team members to also use symlink repositories, you should set the `symlinkDir` value in the local `packman/packman.json` file, which is in `.gitignore` by default. Alternatively, you can set the its value in the global `~/.composer/composer.json`.

### Manual symlink repositories

If you prefer defining your symlink repositories explicitly, it's a good idea to define them in the **global** composer settings. In that way, you don't have to remember to remove the repository specs from each local `composer.json` that needs it.

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

> Use a relative path for the "url" that works for all the members of your dev team. You can either start from your home directory with `~/` or you can make it relative to the root project with `../`. The `~/` is convenient for packages installed globally. The `../` allows for more freedom as long as the package is not installed globally or that the relative path also works from the global `.composer` folder.

### Combining Packman and Symlink Repositories

It is fine and normal to have both symlink repositories and private once managed with <img src="docs/assets/proximify_packman.svg" width="25px" alt="packman icon" style="vertical-align:middle">. The symlink repositories will have higher precedence than the private ones.

Once the Packman plugin is installed to fetch remote private packages, and the global and/or local `composer.json` is configured for any additional local symlink repositories, you can run

```bash
composer require my-org/my-repo:dev-master
```

to get the package defined as the latest development commit of the master branch of the given repository.

### Deployment to Prod

It's not a good idea to use symlink repositories to deploy to a production machine. The symlink repositories are only meant for local development. In contrast, <img src="docs/assets/proximify_packman.svg" width="25px" alt="packman icon" style="vertical-align:middle"> can be used to deploy to a production server (e.g. as a zip bundle). But, if the intent is to fetch the packages on the production server, then the default Packman solution of hosting private packages on `localhost` won't work if that URL is not authenticated in some way. In other words, **Packman is for local machines only**. If used on a server, the Packman URL (`localUrl`) should only be accessible **only** from the local machine.

## Options

The assumption that private packages have the same vendor name than that of the root project might not be correct. The vendor name to use for private packages can be set via a custom parameter.

Custom parameter values can be set in three different places, in the `extra` property of the root project's `composer.json`, in the `extra` property of the global `composer.json` (usually under `~/.composer`), and in the `packman.json` (usually under `./packman`). The three sources are merged, with the packman file having priority over the local composer, and the global composer options having the least priority.

are set in the `composer.json` under the `extra` property. For example,

```json
// composer.json
{
    "name": "my-vendor/my-composer-project",
    "extra": {
        "packman": {
            "vendor": "alt-my-vendor",
            "localUrl": "http://localhost:8081",
            "remoteUrl": "https://github.com"
        }
    }
}
```

If the protocol of the **localUrl** is http instead of https, **packman** will set the `secure-http` to `false` (see [secure-http](https://getcomposer.org/doc/06-config.md#secure-http)). Othewrise, Composer will refuse to contact a URL like `http://localhost:8081`.

### Parameters

The paremeters are set in the global and/or local composer.json files under the keys `extra: {packman: { ... }}`.

| Parameter  | Default value                      | Description                                                                                                                                                                     |
| ---------- | ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| vendor     | `{ROOT-VENDOR}`                    | The vendor name of the private packages to manage.                                                                                                                              |
| remoteUrl  | `https://github.com/{ROOT-VENDOR}` | Base URL of the repository hosting servers where the private repositories are located.                                                                                          |
| localUrl   | `http://localhost:8081`            | The URL to be used for the local server of Composer packages.                                                                                                                   |
| packmanDir | `packman`                          | The folder where to used by Packman to store repositories and the `packman.json` configuration file. It can be local to each project (default), or shared by multiple projects. |
| symlinkDir |                                    | The folder to used for symlinked repositories.                                                                                                                                  |
| symlinks   |                                    | An array of repository names to symlink.                                                                                                                                        |

## Commands

<img src="docs/assets/proximify_packman.svg" width="25px" alt="packman icon" style="vertical-align:middle"> runs automatically when composer runs, and, in general, it does the right thing at the right time. However, it is also possible to run commands on demand using `composer COMMAND`.

 <!-- for local ones and `composer global COMMAND` for those that edit the global composer settings (e.g. `packman:config`). -->

| Parameter      | Description                                                                                                                                                                                                                                                                                                         |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| packman-build  | Perform an update of all registered packages.                                                                                                                                                                                                                                                                       |
| packman-reset  | Reset the entire package store.                                                                                                                                                                                                                                                                                     |
| packman-start  | Build private packages and start the local web server to server them to composer.                                                                                                                                                                                                                                   |
| packman-stop   | Stop the local web server that servers the packages to composer.                                                                                                                                                                                                                                                    |
| packman-link   | Adds the given folder names to the `packman.json` under the symlinks key. Note that tt does not add packages but just local repo folders. Packages requirements have to be added with `composer require`. It only adds the instructions to symlink to repository folders where some required packages can be found. |
| packman-unlink | Remove symlinks from the `packman.json` file.                                                                                                                                                                                                                                                                       |

<!-- | packman:config | Save the values for Packman parameters. Use `composer global packman:config` to save to the global `.composer/composer.json`. |  | -->

## Semantic versioning

By tagging a commit, one can attach a semantic version ([semver](https://semver.org/)) to a commit in order to make it referentiable in relative terms. For example, package "vendor/repo:1.2.0-beta" is the commit in the master brach that has the tag '1.2.0-beta'.

Version suffix labels of the form "-label" provide an additional way to communicate information about the state and intent of a particular commit. The meaning of lables is rank order as: `dev`, `alpha`, `beta`, `rc`, and they can have a numeric suffix too, such as `beta1` or `rc2`. A no-label version number, `n.n.n`, is a higher version than the same number with a label, `n.n.n-label`, for any label.

An untagged commit represents a package with an unknown version number that exists within a range of known version numbers. It's still possible to refer to an untagged commit. For example, one can request the last commit before a tagged commit.

When asking for a "vendor/repo:dev-master" **package**, one is asking for the latest commit in the `master` branch of the "vendor/repo" repository with label `-dev` or higher. For example, a `-alpha` labelled version number would meet that condition.

## About Satis

This plugin uses the [Satis](https://composer.github.io/satis/) repository generator (see [using satis](<[How-to](https://composer.github.io/satis/using)>)).

---

## Contributing

This project welcomes contributions and suggestions.

## License

Copyright (c) Proximify Inc. All rights reserved.

Licensed under the [MIT](https://opensource.org/licenses/MIT) license.

**Software component** is made by [Proximify](https://proximify.com). We invite the community to participate.
