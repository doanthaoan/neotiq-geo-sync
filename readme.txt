=== Neotiq Geo Sync ===
Contributors: neotiq
Tags: taxonomy, geocoding, openstreetmap, acf, jetformbuilder
Plugin URI: https://neotiq.com/
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Derives the country, region, department, arrondissement and city taxonomies from the OpenStreetMap address stored on venues and providers.

== Description ==

Venue (`etablissement`) and provider (`prestataire`) posts carry their address in an ACF OpenStreetMap field. Their location taxonomies, however, are picked by hand by whoever created the post, so they drift: a wrong department, a missing arrondissement, no city at all.

This plugin reads the address back and derives the taxonomy terms from it, so the address is the single source of truth.

= What it writes =

* `eu` — country. Only filled in when the post has none; a disagreement between the post and the address is reported, never silently resolved.
* `localisation` — the full chain Region > Department, extended to Region > Department > City > Arrondissement in Paris, Marseille and Lyon.
* `ville` — French communes. Paris, Marseille and Lyon have no term here by design: their arrondissement in `localisation` fills that role.
* `euville` — cities outside France.

= How an address is resolved =

Coordinates come from the field's marker when there is one, otherwise from the centre of the map. They are then reverse geocoded:

1. **Base Adresse Nationale** (`api-adresse.data.gouv.fr`) first. Its `context` carries the department code directly, `2A` and `2B` included, and its `district` identifies the arrondissement. No quota, no key.
2. **Photon** (`photon.komoot.io`) for everything else, queried in French so it returns the spellings the `euville` taxonomy uses.

Both are queried at most once per second, which their terms of use require. Results are cached in the `_neotiq_geo_cache` post meta and only refetched when the coordinates change, or when the cache is explicitly ignored.

= Map coordinates =

JetEngine's map listings and its geo-distance search read plain lat/lng meta, not the serialised OpenStreetMap field, so the coordinates are mirrored into `map_lat`, `map_lng` and `map_coordinate` on both post types.

This needs no geocoding — it only reads meta the post already carries — so it runs at full speed and lives on its own tab, independent of the address check. You never have to re-check addresses just to refresh coordinates.

Values that already match are left untouched, so a full rewrite is safe. A post with no usable address is reported and left exactly as it is, including any stale coordinates written before its address was cleared: deleting those is a decision for a human, not for a batch job.

The field name must be the same on both post types, since JetEngine is configured with one meta key. The ACF field *keys* must differ, as ACF requires them to be globally unique. The plugin resolves the key from the field groups that apply to each post, because `update_field()` with a bare name resolves non-strictly and would otherwise attach one post type's field key to the other's posts.

= The location line on listing cards =

Six post meta fields, written for every venue and provider and kept up to date
automatically:

    _neotiq_location             26 - Drôme - Valence
                                 69 - Rhône - Lyon - 2e
                                 75 - Paris - 13e
                                 Marrakech
    _neotiq_city                 Valence   Lyon    Paris    Marrakech
    _neotiq_department           Drôme     Rhône   (empty)  (empty)
    _neotiq_dept_code            26        69      75       (empty)
    _neotiq_arrondissement       (empty)   2e      13e      (empty)
    _neotiq_arrondissement_code  (empty)   69002   75013    (empty)

The department code is the two characters that identify the department. Term codes
are not all two characters -- the Métropole de Lyon is "69M", Monaco is "980", and an
imported term can carry a whole postcode -- so they are cut down on the way in. That
is also what keeps "980" from sorting after "95".

For a listing card, point a JetEngine Dynamic Field at `_neotiq_location` and turn on
"Hide if value is empty". A post with no location stores an empty string, so the
widget hides itself — there is no Dynamic Visibility rule to write and no Query
Builder query to run. The same values are available as a shortcode for a text block
or a template:

    [neotiq_location]
    [neotiq_location field="city"]
    [neotiq_location field="department"]
    [neotiq_location field="arrondissement"]

For a search query, ORDER BY `_neotiq_dept_code`, `_neotiq_city` then
`_neotiq_arrondissement_code` with plain meta joins, instead of aggregating over every
term on every post. Sorting on the city sorts by name, because the code is already
stripped. Order the two codes as strings rather than casting them to numbers: they are
fixed width, so a string sort is already the numeric one, and it is the only one that
puts Corsica ("2A", "2B") after 19 rather than after 01.

The terms themselves are never touched. Term names keep the codes they carry
("26000 Valence", "75 Paris"); the code is dropped on the way into these fields.

Paris, Lyon and Marseille carry an arrondissement in the localisation taxonomy
instead of a ville term, and the line follows: code, department, city, arrondissement.
Paris is its own department, so it is printed once, not twice.

= Keeping the listing fields current =

They are rebuilt whenever a post's location terms change, whatever writes them — the
admin, a JetFormBuilder form, this plugin's own sync, an import or WP-CLI — and
whenever one of those terms is renamed. Several taxonomies are written one after
another during a save, so the rebuild is deferred to the end of the request and each
post is built once.

Existing posts need one pass after installing: Tools -> Neotiq Geo Sync -> Listing
data -> Start. It reads no geocoder, so it runs at full speed.


= When it runs =

* **Admin** — on `acf/save_post`, at priority 25: coordinates first, then the taxonomies.
* **Front end** — on `jet-form-builder/modifier/after-run`. Not on `after-post-insert`: JetFormBuilder writes its terms and meta after that hook fires, and would overwrite anything set there.
* **In bulk** — Tools > Neotiq Geo Sync, on either tab.

= The bulk tool =

Tools > Neotiq Geo Sync separates checking from reviewing. A check walks the catalogue in batches over AJAX and writes what it finds to its own table; the results browser underneath reads that table back. Reviewing therefore costs one query, not another pass over every post — which is the difference between usable and unusable on a catalogue of ten thousand.

**Checking.** "New and modified posts only" skips every post already checked and untouched since, so the first pass walks the whole catalogue and later ones touch almost nothing. Stopping and starting again carries on where it left off, so a long first pass survives a closed tab. "Re-check everything" forces a full pass, as does ignoring the geocoding cache.

**Reviewing.** The results are filtered by status and post type, paginated, and summarised by a count per status with the date of the last check. Each row shows the post, its status, the before and after of each taxonomy, the reason when something could not be resolved, and when it was last checked.

**Correcting.** By default nothing is written. Rows that have something to correct get a checkbox; tick the ones you want, then use "Apply the selected corrections". When the filter holds more posts than fit on one page, a second button applies to all of them, so you are never asked to tick thousands of boxes. A confirmation dialog asks you to back up the database first, since corrections replace the existing terms and cannot be undone from this screen.

Ticking "Apply the corrections while checking" writes as it goes instead, and still records every post it touched.

Each post is re-synced at the moment it is applied rather than replayed from the stored result, so a post edited in between gets its current state written, not a stale one.

**Deleting the stored results** frees the table without touching a single post. The next check then has to walk the whole catalogue again.

Statuses are: already correct, to correct, corrected, no address, conflict (the post's country and its address disagree — nothing is written) and error (the geocoder could not be reached).

= Repairing truncated names =

`tools/repair-truncated-names.php` is a one-off maintenance script, not part of the
plugin's normal work. An old import stored term names with `substr( $name, 0,
mb_strlen( $name ) )`, so every name lost one trailing character per accented
character it held: "Bage-le-Cha" for "Bâgé-le-Châtel". The slug was written before
the cut, so the missing tail is read back from there.

It only rewrites a name when putting the tail back reproduces the bytes still in the
database, so an edited title or an accent inside the lost tail is left alone rather
than guessed at. Slugs are never touched, Yoast's cached breadcrumb titles are kept
in step, and every change is written to a CSV in wp-content for rollback.

Dry run by default:

    php tools/repair-truncated-names.php            # report only
    php tools/repair-truncated-names.php --apply    # write

Administrators can also open the file in a browser; applying from there needs the
nonce link the dry run prints. Re-runnable: repaired rows stop matching.


= Storage =

Findings live in one table, `{prefix}neo_geo_results`, holding one row per post rather than a snapshot per run: the post ID, its status, the taxonomy changes and notes as JSON, the label the geocoder returned, the post's modified date at check time, when it was checked, and when corrections were last applied to it.

Keeping the modified date is what makes the incremental mode work: a post whose `post_modified_gmt` is newer than the stored one is due for another check, everything else is skipped.

The table is created on activation, and also on any admin request where the stored schema version is out of date, so updating the files without reactivating still migrates. Uninstalling drops the table, the schema version option, and the cached geocoding results.

== Frequently Asked Questions ==

= Does it overwrite terms chosen by hand? =

Yes. That is the point: the address is authoritative. The front-end edit form still lets owners pick a region and department, and the address will override that choice when the post is saved.

The one exception is the country: when the `eu` term and the geocoded country disagree, the post is flagged as a conflict and nothing at all is written for it.

= What happens to cities that are not in the taxonomy? =

Nothing is written and the row says so, leaving the existing value alone. The bulk tool can create missing `euville` terms when the option is ticked; `ville` terms are never created, since that taxonomy is a fixed list of French communes.

= Why is my city not matched outside France? =

Photon returns the local name when no French one exists ("Σπάτα"), while the taxonomy stores a Latin transliteration ("Spata"). The English rendering is tried as a second guess and spelling variants within two characters are accepted, but transliteration is not guessed. Those posts are reported so they can be handled by hand.

= Does it need an API key? =

No. Both services are open and keyless.

= How long does the first check take? =

Address by address, bounded by the geocoders: both are queried at most once per second, so roughly one post per second for addresses not yet cached, and near-instant for the rest. A ten thousand post catalogue is therefore an afternoon once, and seconds thereafter, since later checks only visit posts that changed.

= Why not store one row per run? =

Because the useful question is "what is the state of this post now", not "what did run 14 say". One row per post, overwritten, answers that in a single query and doubles as the bookkeeping the incremental mode needs. Run history would grow without bound and still not tell you whether a post is currently correct.

== Changelog ==

= 1.3.0 =
* The location line leads with the department code and separates on " - ":
  "44 - Loire-Atlantique - Nantes".
* Two more listing fields, _neotiq_arrondissement and _neotiq_arrondissement_code, so
  a search can sort within a city without joining the terms back in.
* _neotiq_dept_code is cut to the two characters that identify the department.
* Uninstall removes every _neotiq_ post meta by prefix rather than by a list that
  could fall behind.

Existing sites: run Tools > Listing data once after updating. The fields are only
rewritten when a post is touched, so posts nobody edits would keep the old format.

= 1.2.0 =
* Listing fields: _neotiq_location, _neotiq_city, _neotiq_department and
  _neotiq_dept_code, written from the post's own terms and kept up to date on every
  route that changes them, plus a [neotiq_location] shortcode.
* The map coordinates tab became "Listing data" and now rebuilds both sets of fields
  in one pass.
* tools/repair-truncated-names.php, a one-off repair for term names cut short by an
  old import.

= 1.1.0 =
* Store findings in their own table, so reviewing no longer means re-checking.
* Incremental checks: skip posts already checked and untouched since.
* Filter, paginate and correct from the stored results; apply to a whole filtered set at once.
* Absorb the standalone OSM coordinate extraction snippet, now covering providers as well as venues.

= 1.0.0 =
* Initial release.

== Credits ==

Built by [Neotiq](https://neotiq.com/) for 1lieu1salle.

Address data comes from the [Base Adresse Nationale](https://adresse.data.gouv.fr/) (French government, Open Database License) and from [Photon](https://photon.komoot.io/) over OpenStreetMap data (Open Database License).
