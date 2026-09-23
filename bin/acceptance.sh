#!/usr/bin/env bash
#
# Live acceptance for UAMSWP Restrict User Enumeration (spec section 5).
#
# Run against every site after deploy, as an anonymous visitor. Sends no
# credentials and no cookies, which is the whole point: it checks what a
# stranger gets.
#
# All GETs but one. check_batch sends a POST to /wp-json/batch/v1, because that
# is the only vector that distinguishes this plugin from something else that
# answers 401 on the plain URLs. It creates nothing; see the note above it.
#
#   bin/acceptance.sh https://example.uams.edu
#   bin/acceptance.sh -f sites.txt          # one origin per line, # for comments
#
# Exit status is 0 when every check on every site passed, 1 otherwise, so this
# can be wired straight into a monitor.

set -uo pipefail

# Redirects are not followed: for ?author= the Location header is the finding,
# and everything else is asked for at the URL it should answer on.
CURL_OPTS=(--silent --show-error --max-time 20
	--user-agent 'uamswp-rue-acceptance/1.0')

pass_count=0
fail_count=0
warn_count=0

green()  { printf '\033[0;32m%s\033[0m' "$1"; }
red()    { printf '\033[0;31m%s\033[0m' "$1"; }
yellow() { printf '\033[0;33m%s\033[0m' "$1"; }

# record <pass|fail|warn> <label> <detail>
#
# warn is for things outside this plugin's scope that someone still has to go
# and fix, so they are reported without failing the deploy gate.
record() {
	case "$1" in
		pass)
			pass_count=$(( pass_count + 1 ))
			printf '  %s  %-34s %s\n' "$(green PASS)" "$2" "$3"
			;;
		warn)
			warn_count=$(( warn_count + 1 ))
			printf '  %s  %-34s %s\n' "$(yellow WARN)" "$2" "$3"
			;;
		*)
			fail_count=$(( fail_count + 1 ))
			printf '  %s  %-34s %s\n' "$(red FAIL)" "$2" "$3"
			;;
	esac
}

# expect_status <site> <path> <expected-code> [expected-redirect-prefix]
expect_status() {
	local site="$1" path="$2" want="$3" want_location="${4:-}"
	local got code location

	got=$(curl "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code} %{redirect_url}' "${site}${path}" 2>/dev/null)
	code="${got%% *}"
	location="${got#* }"

	if [ "$code" != "$want" ]; then
		record fail "$path" "want ${want}, got ${code} ${location}"
		return
	fi

	if [ -n "$want_location" ] && [ "${location#"$want_location"}" = "$location" ]; then
		record fail "$path" "${code} but redirected to ${location}"
		return
	fi

	record pass "$path" "${code}${location:+ -> $location}"
}

# The users routes must refuse a stranger, however the route is spelled.
check_rest() {
	local site="$1"
	expect_status "$site" "/wp-json/wp/v2/users" 401
	expect_status "$site" "/wp-json/wp/v2/Users" 401       # case bypass (FR1)
	expect_status "$site" "/wp-json/wp/v2/USERS" 401
	expect_status "$site" "/wp-json/wp/v2/users/1" 401
	expect_status "$site" "/wp-json/wp/v2/users/" 401
	expect_status "$site" "/?rest_route=/wp/v2/users" 401
	expect_status "$site" "/?rest_route=/wp/v2/Users" 401
}

# The batch envelope is the one vector a per-request check cannot see. A hook
# like rest_authentication_errors fires once, against the outer /batch/v1
# request, so a users sub-request sitting in the POST body goes through
# untouched. Only a per-dispatch check (rest_pre_dispatch, which is what this
# plugin uses) refuses it.
#
# That makes this the check that says whether THIS plugin is loaded, rather than
# something else that happens to answer 401 on the plain URLs. On a site with a
# pre-existing blocker every other check here passes either way; this one does
# not.
#
# It is the only request in this script that is not a GET, and it creates
# nothing: the sub-request carries no body, it is refused before the handler
# runs, and core requires create_users regardless.
check_batch() {
	local site="$1" out code body

	out=$(curl "${CURL_OPTS[@]}" -w '\nHTTPSTATUS:%{http_code}' \
		-X POST "${site}/wp-json/batch/v1" \
		-H 'Content-Type: application/json' \
		-d '{"requests":[{"method":"POST","path":"/wp/v2/users"}]}' 2>/dev/null)
	code="${out##*HTTPSTATUS:}"
	body="${out%%HTTPSTATUS:*}"

	case "$code" in
		404)
			# /batch/v1 arrived in WP 5.6. Nothing to close on older cores.
			record warn "batch /wp/v2/users" "no /batch/v1 endpoint (WordPress older than 5.6)"
			;;
		5*)
			# A WP_Error returned from rest_pre_dispatch is a TypeError here on
			# WP 7.0+. See the README, "Deviations from the spec".
			record fail "batch /wp/v2/users" "HTTP ${code}: fatal in the batch handler, not a 401"
			;;
		*)
			if printf '%s' "$body" | grep -q '"rest_forbidden"'; then
				record pass "batch /wp/v2/users" "sub-request refused 401"
			elif printf '%s' "$body" | grep -q '"rest_missing_callback_param"'; then
				record fail "batch /wp/v2/users" "sub-request reached the handler: this plugin is not loaded"
			else
				record fail "batch /wp/v2/users" "unexpected ${code}: $(printf '%s' "$body" | head -c 120)"
			fi
			;;
	esac
}

# Nothing identifying an author may appear in a posts response a stranger can
# ask to have the author embedded into.
#
# What matters is whether the embedded author carries an identity, not whether
# an _embedded block exists. Any error object in there is a refusal, whoever
# issued it: this plugin answers rest_forbidden, Wordfence's equivalent option
# makes the lookup fail with rest_user_invalid_id, and both are fine. Treating
# the presence of "_embedded" as the failure reports a leak on a site that is
# refusing correctly.
check_embed() {
	local site="$1" body author

	body=$(curl "${CURL_OPTS[@]}" "${site}/wp-json/wp/v2/posts?per_page=1&_embed=author" 2>/dev/null)

	author=$(printf '%s' "$body" | python3 -c '
import json, sys
try:
    posts = json.load(sys.stdin)
except Exception:
    print("UNREADABLE"); raise SystemExit
if not isinstance(posts, list) or not posts:
    print("NOPOSTS"); raise SystemExit
entries = (posts[0].get("_embedded") or {}).get("author") or []
if not entries:
    print("NOAUTHOR"); raise SystemExit
a = entries[0]
if not isinstance(a, dict) or "code" in a:
    print("REFUSED " + str(a.get("code", "")))
elif a.get("slug") or a.get("name"):
    print("LEAK slug=%s name=%s" % (a.get("slug", ""), a.get("name", "")))
else:
    print("REFUSED")
' 2>/dev/null)

	case "$author" in
		REFUSED*)   record pass "_embed=author" "embedded author refused (${author#REFUSED })" ;;
		NOAUTHOR)   record pass "_embed=author" "no author embedded in response" ;;
		# No posts means this check proved nothing on this site, which is worth
		# saying rather than banking as a pass.
		NOPOSTS)    record warn "_embed=author" "site has no posts; check not exercised" ;;
		UNREADABLE) record fail "_embed=author" "could not parse the posts response" ;;
		LEAK*)      record fail "_embed=author" "author data embedded: ${author#LEAK }" ;;
		*)          record fail "_embed=author" "unexpected: ${author:-no output}" ;;
	esac
}

# /wp-sitemap-users-1.xml is a list of author archive URLs, so slugs.
check_sitemap() {
	local site="$1"
	expect_status "$site" "/wp-sitemap-users-1.xml" 404

	# Followed, because an SEO plugin may redirect the core index to its own
	# (SEOPress sends /wp-sitemap.xml to /sitemaps.xml). Without following, the
	# body is empty and an empty body greps clean, which reads as a pass.
	local index
	index=$(curl "${CURL_OPTS[@]}" --location --max-redirs 3 "${site}/wp-sitemap.xml" 2>/dev/null)

	if [ -z "$index" ]; then
		record warn "sitemap index" "no readable sitemap index; check it by hand"
	elif printf '%s' "$index" | grep -qi 'sitemap-users\|author'; then
		record fail "sitemap index" "still lists a users or author sitemap"
	else
		record pass "sitemap index" "no users sitemap listed"
	fi
}

# SEOPress publishes its own author sitemap at /author.xml when
# seopress_xml_sitemap_author_enable is on. That is out of this plugin's scope
# by decision (spec section 2), so it is reported rather than failed: turn it
# off in SEOPress > XML Sitemap on any site that answers here.
check_seo_author_sitemap() {
	local site="$1" code

	code=$(curl "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code}' "${site}/author.xml" 2>/dev/null)

	if [ "$code" = "200" ]; then
		record warn "/author.xml (SEOPress)" "200: author sitemap is enabled, disable it in SEOPress"
	else
		record pass "/author.xml (SEOPress)" "${code}"
	fi
}

# ?author=N must not be answered with /author/<nicename>/.
check_author_query() {
	local site="$1" got code location

	got=$(curl "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code} %{redirect_url}' "${site}/?author=1" 2>/dev/null)
	code="${got%% *}"
	location="${got#* }"

	if [ "$code" = "301" ] && [ -n "$location" ] && ! printf '%s' "$location" | grep -qi '/author/'; then
		record pass "/?author=1" "301 -> ${location}"
	else
		record fail "/?author=1" "want 301 away from /author/, got ${code} ${location}"
	fi
}

# oEmbed must name the site, not the person who wrote the post.
check_oembed() {
	local site="$1" body author_name author_url

	# PHP escapes forward slashes in JSON by default, so author_url arrives as
	# https:\/\/host\/author\/slug\/ and an unescaped match silently passes.
	body=$(curl "${CURL_OPTS[@]}" "${site}/wp-json/oembed/1.0/embed?url=${site}/" 2>/dev/null | sed 's|\\/|/|g')
	author_name=$(printf '%s' "$body" | sed -n 's/.*"author_name":"\([^"]*\)".*/\1/p')
	author_url=$(printf '%s' "$body" | sed -n 's/.*"author_url":"\([^"]*\)".*/\1/p')

	if [ -z "$body" ]; then
		record fail "oembed author fields" "no response"
	elif [ -z "$author_url" ]; then
		record fail "oembed author fields" "no author_url in response; inspect it"
	elif printf '%s' "$author_url" | grep -qi '/author/'; then
		record fail "oembed author fields" "author_url is an author archive: ${author_url}"
	else
		record pass "oembed author fields" "author_name=${author_name:-<absent>} author_url=${author_url}"
	fi
}

# Ordinary anonymous reads must keep working. A control, so a site that is
# simply down does not read as a pass.
check_no_collateral() {
	local site="$1"
	expect_status "$site" "/wp-json/wp/v2/posts" 200
	expect_status "$site" "/wp-json/" 200
}

# Every other check reads a status code, so a host that redirects everything
# somewhere else (www to apex, http to https) would report a wall of 301s that
# say nothing about the plugin. Catch that once, up front.
check_canonical_host() {
	local site="$1" location

	location=$(curl "${CURL_OPTS[@]}" -o /dev/null -w '%{redirect_url}' "${site}/wp-json/" 2>/dev/null)

	if [ -n "$location" ]; then
		record warn "canonical host" "redirects to ${location}; re-run against that origin"
		return 1
	fi

	return 0
}

check_site() {
	local site="${1%/}"
	printf '\n%s\n' "$site"
	check_canonical_host "$site" || true
	check_rest "$site"
	check_batch "$site"
	check_embed "$site"
	check_sitemap "$site"
	check_seo_author_sitemap "$site"
	check_author_query "$site"
	check_oembed "$site"
	check_no_collateral "$site"
}

main() {
	local sites=()

	if [ "${1:-}" = "-f" ]; then
		[ -n "${2:-}" ] || { echo "usage: $0 -f <file>" >&2; exit 2; }
		while IFS= read -r line; do
			line="${line%%#*}"
			line="$(printf '%s' "$line" | tr -d '[:space:]')"
			[ -n "$line" ] && sites+=( "$line" )
		done < "$2"
	elif [ $# -gt 0 ]; then
		sites=( "$@" )
	else
		echo "usage: $0 <site-url> [site-url ...]" >&2
		echo "       $0 -f <file-of-site-urls>" >&2
		exit 2
	fi

	for site in "${sites[@]}"; do
		check_site "$site"
	done

	printf '\n%d passed, %d failed, %d to follow up\n' "$pass_count" "$fail_count" "$warn_count"
	[ "$fail_count" -eq 0 ]
}

main "$@"
