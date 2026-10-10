# Maintaining

## Publish a new version

[Packagist](https://packagist.org) publishes the package from the Git tags, so there is no version in `composer.json` and nothing to upload.

1. Check that CI passes on `main`.
2. Tag and push: `git tag v1.2.3 && git push origin v1.2.3`
3. Check that the version is on the [package page](https://packagist.org/packages/soml/soml).
4. Create a release on GitHub.

Do not move or delete a published tag. Composer caches the code of each version, so a changed tag gives different users different code.
