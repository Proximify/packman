<p align="center">
  <img src="docs/assets/proximify_packman.svg" width="300px" alt="proximify packman plugin">
</p>

# Packman

Composer plugin for managing private packages using a local packaging server.

## How it works

The plugin reads the composer.json of the root project an assumes that required packages with the same namespace than that of the root project should be managed by a **local packaging server**.

### Steps

1. Read composer.json and search for requirements in the same namespace than the root project.
2. Create a [Satis](https://composer.github.io/satis/) repository in the sub-folder **private_packages** with the selected packages.
3. Start a PHP buil-in web server on the **private_packages** folder at http://localhost:8081`.

## Getting started

The plugin includes CLI commands to setup `composer.json` so that it can fetch packages from the local packaging server.

Run

```bash
$ composer packman-init --interactive
```

to get an interactive version of the initialization step that props for some basic configuration options.

## About Satis

This plugin uses the [Satis](https://composer.github.io/satis/) repository generator (see [using satis]([How-to](https://composer.github.io/satis/using))).

---

## Contributing

This project welcomes contributions and suggestions.

## License

Copyright (c) Proximify Inc. All rights reserved.

Licensed under the [MIT](https://opensource.org/licenses/MIT) license.

**Software component** is made by [Proximify](https://proximify.com). We invite the community to participate.
