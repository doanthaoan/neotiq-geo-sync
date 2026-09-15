=== Neotiq Geo Sync ===
Contributors: neotiq
Tags: taxonomy, geocoding, openstreetmap, acf, jetformbuilder
Plugin URI: https://neotiq.com/
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
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

= When it runs =

* **Admin** — on `acf/save_post`, at priority 25.
* **Front end** — on `jet-form-builder/modifier/after-run`. Not on `after-post-insert`: JetFormBuilder writes its terms and meta after that hook fires, and would overwrite anything set there.
* **In bulk** — Tools > Neotiq Geo Sync.

= The bulk tool =

Tools > Neotiq Geo Sync separates checking from reviewing. A check walks the catalogue in batches over AJAX and writes what it finds to its own table; the results browser underneath reads that table back. Reviewing therefore costs one query, not another pass over every post — which is the difference between usable and unusable on a catalogue of ten thousand.

**Checking.** "New and modified posts only" skips every post already checked and untouched since, so the first pass walks the whole catalogue and later ones touch almost nothing. Stopping and starting again carries on where it left off, so a long first pass survives a closed tab. "Re-check everything" forces a full pass, as does ignoring the geocoding cache.

**Reviewing.** The results are filtered by status and post type, paginated, and summarised by a count per status with the date of the last check. Each row shows the post, its status, the before and after of each taxonomy, the reason when something could not be resolved, and when it was last checked.

**Correcting.** By default nothing is written. Rows that have something to correct get a checkbox; tick the ones you want, then use "Apply the selected corrections". When the filter holds more posts than fit on one page, a second button applies to all of them, so you are never asked to tick thousands of boxes. A confirmation dialog asks you to back up the database first, since corrections replace the existing terms and cannot be undone from this screen.

Ticking "Apply the corrections while checking" writes as it goes instead, and still records every post it touched.

Each post is re-synced at the moment it is applied rather than replayed from the stored result, so a post edited in between gets its current state written, not a stale one.

**Deleting the stored results** frees the table without touching a single post. The next check then has to walk the whole catalogue again.

Statuses are: already correct, to correct, corrected, no address, conflict (the post's country and its address disagree — nothing is written) and error (the geocoder could not be reached).

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

= 1.0.0 =
* Initial release.

== Credits ==

Built by [Neotiq](https://neotiq.com/) for 1lieu1salle.

Address data comes from the [Base Adresse Nationale](https://adresse.data.gouv.fr/) (French government, Open Database License) and from [Photon](https://photon.komoot.io/) over OpenStreetMap data (Open Database License).
