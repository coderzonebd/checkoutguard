# Releasing CheckoutGuard

1. Bump the version in `checkoutguard.php` (header + `CG_VERSION`) and
   `readme.txt` (`Stable tag` + a `= X.Y.Z =` entry under `== Changelog ==`)
   in a PR to `dev`.
2. Open a PR `dev` -> `prod` (prod guard allows only `dev`) and merge it.
3. Tag the prod commit and push the tag:
   `git tag -a vX.Y.Z -m "CheckoutGuard X.Y.Z" origin/prod && git push origin vX.Y.Z`
4. `release.yml` checks versions, lint and that the SVN tag is free, builds
   `checkoutguard-X.Y.Z.zip` (.distignore applied) with SHA256SUMS and a
   provenance attestation, and opens a **draft** release.
5. Review the draft on GitHub and press **Publish release**.
6. `wporg-publish.yml` downloads that zip, verifies SHA256SUMS and deploys the
   same files to WordPress.org SVN (trunk + tags/X.Y.Z), then checks the tag.

Tags with a `-` (e.g. `v1.5.0-rc.1`) become prereleases and never go to
WordPress.org. `wporg-release.yml` stays as a manual fallback.
