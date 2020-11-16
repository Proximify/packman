<p align="center">
  <img src="docs/assets/proximify_packman.svg" width="300px" alt="proximify packman plugin">
</p>

# Packman

Composer plugin for managing private packages using a local packaging server.

## How it works

The plugin reads the `composer.json` of the root project an assumes that required packages with the same namespace than that of the root project should be managed by a **local packaging server**.

### Steps

1. Read the `composer.json` of the root project and search for requirements in the same namespace than the root project.
2. Create a [Satis](https://composer.github.io/satis/) repository in the sub-folder **private_packages** with the selected packages.
3. Start a PHP built-in web server on the **private_packages** folder at http://localhost:8081`.

## Getting started

Add the plugin to the development dependencies of your project or globally (recommended)

```bash
# Local install
$ composer require proximify/packman --dev
```

```bash
# Global install
$ composer require proximify/packman --global
```

The plugin includes CLI commands to setup `composer.json` so that it can fetch packages from the local packaging server. The `packman-init` commands must be run at least once before attempting to fetch private packages. That is, run it before running `composer install` to get the packages of the project.

Run

```bash
$ composer packman-init
```
to setup your project using the default values for all [parameters](#parameters) (the alt notation `init-packman` works too).

Custom parameter values can be set in the `composer.json` under the `extras` property. For example,

```json
// composer.json
{
    "name":"my-namespace/my-composer-project",
    "extras": {
        "packman": {
            "localUrl": "http://localhost:8081"
        }
    }
}
```

The parameter values for the plugin must added to the `composer.json` before running **packman-init**. It is okay to re-run **packman-init** whenever the parameters change.

The **packman-init** command will be edit the `composer.json` file in order to 

If the protocol of the **localUrl** is http instead of https, **packman-init** edits the `composer.json` file and adds `secure-http: false` to the [config]([secure-http](https://getcomposer.org/doc/06-config.md#secure-http)) property.

```json
// composer.json
 "repositories": [
     {
         "type": "composer",
         "url": "http://localhost:8081/"
     }
 ],
 "config": {
        "secure-http": false
 },
```

## Parameters

| Parameter | Default value                         | Description                                                                           |
| --------- | ------------------------------------- | ------------------------------------------------------------------------------------- |
| namespace | `{ROOT-NAMESPACE}`                    | The namespace of the private packages to manage                                       |
| remoteUrl | `https://github.com/{ROOT-NAMESPACE}` | Base URL of the repository hosting servers where the private repositories are located |
| localUrl  | `http://localhost:8081`               | The URL to be used for the local server of Composer packages                          |



## About Satis

This plugin uses the [Satis](https://composer.github.io/satis/) repository generator (see [using satis]([How-to](https://composer.github.io/satis/using))).

---

## Contributing

This project welcomes contributions and suggestions.

## License

Copyright (c) Proximify Inc. All rights reserved.

Licensed under the [MIT](https://opensource.org/licenses/MIT) license.

**Software component** is made by [Proximify](https://proximify.com). We invite the community to participate.
