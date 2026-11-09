# API Use Cases

This guide describes the runnable CommissionXplorer requests in the supplied
Insomnia collection. The collection defines two base URLs:

| Environment | Base URL |
| --- | --- |
| Local | `http://localhost:8000/api/` |
| Production | `https://naslku.synology.me/commissionXplorerAPI/` |

Use the base URL appropriate to your deployment and append the endpoint path
shown below.

## Browse artists by content rating

List artists and their thumbnail paths with `GET /artists.php`. The collection
tests the `isNsfw` query parameter with no value, booleans, levels, and invalid
values:

```http
GET /artists.php?isNsfw=2
```

In the current implementation, omitting the parameter or using `false` selects
level 0. Numeric values select that exact level (values above 4 are capped at
4); boolean spellings of `true` parse to level 2, but only the exact lowercase
string `true` gets the special behavior of selecting levels 1 and 2. For
example, `TRUE` selects only level 2. Other values that cannot be parsed as a
non-negative integer or boolean, including `-1`, decimals, and arbitrary text,
fall back to level 0. The response is an artist-to-thumbnail-path map.

> `artists.php` uses exact-level filtering for numeric values, unlike
> `last.php`, which uses a maximum level. Keep this distinction in mind when
> building clients.

Use `inclusive=true` to include artists at or below a numeric level:

```http
GET /artists.php?isNsfw=2&inclusive=true
```

The `inclusive` option does not change the special lowercase `isNsfw=true`
behavior. With no matching artists, the endpoint returns an empty JSON array.
An artist with no usable thumbnail gets the default folder image.

Artist results are cached for up to 10 minutes. The cache key uses the parsed
rating level but not `inclusive` or the special lowercase-`true` filter. As a
result, requests such as `isNsfw=true` and `isNsfw=2` (or inclusive and
non-inclusive numeric requests) can temporarily reuse each other's cached
result, depending on which request populated the cache first.

The Insomnia collection includes boundary and invalid-input probes (`0`, `1`,
`2`, `3`, `4`, `99`, `-1`, an empty value, `banana`, and `TRUE`). These probe
how the API handles inputs; they do not assert that every value is a supported
rating.

## Find the latest commission

Retrieve the latest commission for the default (level 0) content rating:

```http
GET /last.php
```

To include content up to level 2, as in the collection's NSFW request:

```http
GET /last.php?isNsfw=true
```

`last.php` treats the supplied level as a maximum and returns each eligible
artist's newest commission and timestamp, ordered newest first. It uses the
same level parser as described above, so missing, `false`, or invalid values
select level 0, and a numeric value above 4 is capped at 4. Unlike
`artists.php`, a numeric value includes all artists whose level is at or below
the requested maximum.

If there are no eligible artists, the endpoint returns an empty JSON array. If
eligible artists have no commission files, the collection does not define a
response contract; clients should not assume a particular JSON shape.

## List an artist's artworks

The collection requests artworks for an artist using `GET /artworks.php` with
a JSON body:

```http
GET /artworks.php
Content-Type: application/json

{"artist":"Kuronoyuu"}
```

The endpoint returns the artist's commission file paths. It requires a known,
non-empty artist name.

| Input case | Result |
| --- | --- |
| `artist` missing, empty, or not a string | HTTP 400, `{"error":"Artist not provided"}` |
| Artist name not present in `levels.json` | HTTP 404, `{"error":"Artist not recognized"}` |
| Known artist whose commissions directory is missing or unreadable | Empty JSON array |
| Known artist with no eligible files | Empty JSON array |

Because a JSON body on `GET` is not handled consistently by all clients and
intermediaries, use a client that preserves this request body. An absent or
malformed body may be treated as a missing artist and return HTTP 400.

## Create or retrieve artwork thumbnails

Send one or more relative artwork paths to `POST /thumbnail.php`:

```http
POST /thumbnail.php
Content-Type: application/json

{"artworks":["../commissions/Kuronoyuu/1044.jpg"]}
```

The response contains the artwork and corresponding thumbnail paths. A
thumbnail is generated when one does not already exist. The collection
demonstrates the plural `artworks` form; the endpoint also accepts a singular
`artwork` value.

### Thumbnail edge and failure cases

- Each supplied path must identify an existing commission file. A missing file
  causes `getFullPaths()` to throw; this endpoint does not convert that failure
  to a documented JSON error response.
- Send a non-empty `artwork` string or an `artworks` array of strings. The
  endpoint does not validate the array before iterating it; a missing,
  non-array, or malformed value is not a supported empty-list request.
- Supply only known paths from the configured commissions tree; the endpoint
  resolves paths on the server and is not an artwork upload API.
- A request with `artwork` set to an empty string falls through to the plural
  parameter branch.

## Check thumbnail progress for one artist

Request the thumbnail completion ratio for an artist with
`POST /api/progress.php`:

```http
POST /api/progress.php
Content-Type: application/json

{"action":"thumbnails","artist":"Valia M'tana"}
```

The response is a number between `0.0` and `1.0`, where `1.0` represents
complete thumbnail coverage. Note that the saved local Insomnia URL appends
`api/progress.php` to the `api/` project prefix, resolving to
`http://localhost:8000/api/api/progress.php`. The endpoint file in this
repository is `api/progress.php`; check the local routing configuration if
these paths do not match your setup.

The supported action is `thumbnails`. An unknown or missing action throws an
exception rather than returning a documented error object. The endpoint does
not validate the `artist` parameter: an omitted value is treated as an empty
name and can scan the configured repository roots rather than one artist.
Other malformed values may be coerced or cause a type error. A valid artist
name with no matching commission images yields `0.0`.

## Check thumbnail progress for all artists

Retrieve the completion ratios for all configured artists with
`GET /showAllProgress.php`. The response maps artist names to their progress
ratios. An invalid or empty `levels.json` is treated as an empty artist list
and yields an empty JSON array. Artists with no commission images have a
progress ratio of `0.0`; ratios are capped at `1.0`.

## Error handling and client expectations

Only `artworks.php` defines explicit client errors in the saved implementation:
HTTP 400 for a missing/invalid artist parameter and HTTP 404 for an unknown
artist. The other endpoints generally do not define a consistent error status
or JSON error shape for invalid input, missing files, or configuration
problems. Clients should check the HTTP status and JSON parsing result and
avoid assuming that every failure returns an `{ "error": ... }` object.

## Collection coverage

The export also contains empty request folders for the CommissionXplorer
production environment, GposesXplorer, and FanfictionsBookmarks. Those folders
name areas such as sources, folders, authors, fandoms, tags, fanfictions, and
series, but the export does not include runnable requests for them. This guide
therefore documents only the concrete CommissionXplorer requests present in
the export.

## Run the use-case tests

Run the integration tests from the repository root:

```sh
./run-tests.sh
```

You can also run the PHP test script directly with `php tests/run.php`.
The runner starts a local PHP development server and uses temporary fixture
files; it does not modify the repository's commission or thumbnail data. PHP
8.1 or later and the Imagick extension are required.

GitHub Actions runs the same test command on pushes, pull requests, and manual
workflow runs. Each run writes `test.log` at the repository root and uploads it
as a 30-day artifact. To enable Discord notifications, add a repository Actions
secret named `DISCORD_WEBHOOK_URL` containing the channel's webhook URL. If the
secret is not configured, tests still run and the notification step emits a
warning.
