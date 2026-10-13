# The ERP opens on sign-in: the welcome page is gone

The ERP's old welcome page at `/` is removed. Its heading, three feature cards and
*Get started* / *Log in* were a leftover from the starter kit. The landing site
(`landing/`) is now the public front door, and opening the ERP, from the landing
site's *Open SYNAPSE* or by typing its address, goes straight to sign-in.
[ADR 0084](../decisions/0084-the-erp-has-no-front-page.md).

## Highlights

- **A guest at `/` is sent to sign in.** No second sales page, no second *Log in* click.
- **Somebody already signed in at `/` goes where signing in lands:** the workspace
  picker (`fortify.home`), which passes a single-membership user on to the dashboard.

## Backend

- New `HomeController` (invokable): a guest is redirected to `login`, a signed-in user
  to `config('fortify.home')`. `routes/web.php` maps `/` to it, keeping the name
  `home`, so logout, account deletion, the sign-in screens' logo (`home()`) and the
  public pages' `href="/"` all still resolve. `RequireCompanySetup` still lets `home`
  through.

## Frontend

- Removed `resources/js/pages/welcome.tsx` and its `name === 'welcome'` case in
  `app.tsx`'s layout switch. The product tour's own "welcome" stop and the setup
  wizard's welcome view are unrelated and unchanged.

## Tests

- `tests/Feature/ExampleTest.php` (which asserted `/` returned 200) is replaced by
  `tests/Feature/HomeTest.php`: a guest is redirected to sign-in, a signed-in user to
  `fortify.home`. The existing logout and delete-account tests still assert a redirect
  to `home`, and they pass unchanged.

## Docs

- `modules/legal-documents.md`: the footers that link the legal pages no longer include
  the welcome page; the landing site's footer does.
- ADR 0084.

## Notes

- The sign-in screens' logo links to `/`, which now leads back to sign-in. Pointing it
  at the landing site would need the landing site's address in configuration. Left for
  when that's wanted.
