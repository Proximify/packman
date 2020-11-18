<p align="center">
  <img src="docs/assets/proximify_packman.svg" width="300px" alt="proximify packman plugin">
</p>

# Packman

Composer plugin for managing private packages using a local packaging server.

## How it works

The plugin reads the `composer.json` of the root project an assumes that required packages with the same namespace than that of the root project should be managed by a **local packaging server**.

### Steps

1. Read the `composer.json` of the root project and search for requirements in the same namespace than the root project. Also consider the new packages being required via the CLI.
2. Create a [Satis](https://composer.github.io/satis/) repository in the sub-folder **private_packages** with the selected packages.
3. Start a PHP built-in web server on the **private_packages/repos** folder at `localhost:8081` for the duration of any Composer command, and close it when done.
4. Tell Composer to also look for repositories in the temporary local packaging server at `localhost:8081`.

## Getting started

Add the plugin to the development dependencies of your project

```bash
$ composer require proximify/packman --dev
```

That should be all if the default parameter values for namespace, localUrl and remoteUrl are appropriate. Moreover, the folder `private_packages` is added to `.gitignore` automatically, so that's also taken care of 🥳.

> **Important:** This solution assumes that the standard ssh credential required to fetch the needed repositories have been set up already.

## Non-default options

The assumption that private packages have the same namespace than that of the root project might not be correct. The namespace to use for private packages can be set via a custom parameter.

Custom parameter values are set in the `composer.json` under the `extras` property. For example,

```json
// composer.json
{
    "name": "my-namespace/my-composer-project",
    "extras": {
        "packman": {
            "namespace": "some-namespace",
            "localUrl": "http://localhost:8081",
            "remoteUrl": "https://github.com"
        }
    }
}
```

If the protocol of the **localUrl** is http instead of https, **packman** will set the `secure-http` to `false` (see [secure-http](https://getcomposer.org/doc/06-config.md#secure-http)). Othewrise, Composer will refuse to contact a URL like `http://localhost:8081`.

### Parameters

| Parameter | Default value                         | Description                                                                           |
| --------- | ------------------------------------- | ------------------------------------------------------------------------------------- |
| namespace | `{ROOT-NAMESPACE}`                    | The namespace of the private packages to manage                                       |
| remoteUrl | `https://github.com/{ROOT-NAMESPACE}` | Base URL of the repository hosting servers where the private repositories are located |
| localUrl  | `http://localhost:8081`               | The URL to be used for the local server of Composer packages                          |

## About Satis

This plugin uses the [Satis](https://composer.github.io/satis/) repository generator (see [using satis](<[How-to](https://composer.github.io/satis/using)>)).

---

## Contributing

This project welcomes contributions and suggestions.

## License

Copyright (c) Proximify Inc. All rights reserved.

Licensed under the [MIT](https://opensource.org/licenses/MIT) license.

**Software component** is made by [Proximify](https://proximify.com). We invite the community to participate.
