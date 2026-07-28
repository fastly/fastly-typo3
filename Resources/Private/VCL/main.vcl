# Fastly custom VCL entry point for the TYPO3 "fastly" extension.
#
# Each feature lives in its own custom VCL file, pulled in below. Feature files
# define the built-in subroutines (vcl_recv, vcl_fetch, ...) directly; Fastly
# concatenates same-named built-in subroutines in the order the compiler
# encounters them (https://www.fastly.com/documentation/reference/vcl/subroutines).
#
# The includes MUST stay at the top: the boilerplate subroutines below carry the
# terminating returns (and the #FASTLY macros), and any feature code encountered
# after a terminating return of the same subroutine would be unreachable. Include
# order also matters within vcl_fetch — see caching.vcl. Site packages overriding
# main.vcl must preserve this ordering.

sub vcl_recv {

  if (req.http.Fastly-SSL != "1") {
    error 810 "Force HTTPS";
  }

  # Check if Fastly DDoS Protection flagged the request
  if (fastly.ddos_detected) {
    # Block traffic by returning an error status (e.g., 429 Too Many Requests or 403 Forbidden)
    error 429 "DDoS Attack Mitigated";
  }

  # Set Fastly-Client-IP at the edge, this is a shield-safe alternative to client.ip
  if (fastly.ff.visits_this_service == 0 && req.restarts == 0) {
    set req.http.Fastly-Client-IP = client.ip;

    # Add the ASN for the Fastly NGWAF
    set req.http.X-Client-ASN = client.as.number;
  }

  # Capture the JA4 Fingerprint (Requires Fastly TLS/JA4 feature enabled)
  if (tls.client.ja4) {
    set req.http.X-Client-JA4 = tls.client.ja4;
  } else {
    set req.http.X-Client-JA4 = "JA4-NOT-ENABLED";
  }

  # Static assets don't need any cookie. Stripping them ensures a stable cache
  # key when assets are served from the same domain as the storefront, so the
  # hash does not vary based on sw-cache-hash or sw-currency.
  if (req.url.path ~ "(?i)^/(typo3temp|fileadmin|_assets)/") {
    unset req.http.Cookie;
  }

  # Disable ngwaf inspection for static assets
  if( req.url.ext ~ "(?i)^(html|css|js|gif|png|jpg|jpeg|svg|woff|woff2|ttf|eot|otf)$" ) {
    set req.http.x-sigsci-no-inspection = "true";
  }
  if (req.url.path ~ "(?i)^/(typo3temp|fileadmin|_assets)/") {
    set req.http.x-sigsci-no-inspection = "true";
  }

  if (req.url.path ~ "(?i)^/(_images)/") {
    unset req.http.Cookie;
    set req.http.x-fastly-imageopto-api = "fastly";

    # Branch logic between Edge and Shield nodes
    if (req.http.Fastly-FF) {
      # Rewrite the URL to remove the virtual segment before sending to origin.
      # `req.url` is rewritten to preserve the query string (e.g., ?width=200).
      set req.url = regsub(req.url, "(?i)^/_images(/.*)", "\1");
    }
  }

  # Don't allow clients to force a pass
  if (req.restarts == 0) {
    unset req.http.x-pass;
  }

  # Enable Fastly authentification for single purges
  set req.http.Fastly-Purge-Requires-Auth = "1";

  # Mitigate httpoxy application vulnerability, see: https://httpoxy.org/
  unset req.http.Proxy;

  # Strip query strings only needed by browser javascript. Customize to used tags.
  if (req.url != req.url.path) {
      set req.url = querystring.regfilter(req.url, "^(_ga|_gac.*|_gl|adgid|adid|camid|chl|cof|cx|dclid|dv|fbclid|gbraid|gclid|gclsrc|ie|igshid|kw|kwid|mc_cid|mc_eid|msclkid|nk|pa|piwik_.*|pk_.*|utm_.*|wbraid|yclid)$");
  }

  # Normalize query arguments
  set req.url = querystring.sort(req.url);

  # Make sure that the client ip is forwarded to the origin.
  # Only do this on the edge POP (fastly.ff.visits_this_service == 0) and on the
  # first pass (req.restarts == 0). On the shield node Fastly-Client-IP is the edge
  # POP's IP (not the real client), and on restarts the block would run again, so
  # without this guard X-Forwarded-For ends up with extra/wrong entries.
  if (fastly.ff.visits_this_service == 0 && req.restarts == 0) {
    if (req.http.x-forwarded-for) {
      set req.http.X-Forwarded-For = req.http.X-Forwarded-For + ", " + req.http.Fastly-Client-IP;
    } else {
      set req.http.X-Forwarded-For = req.http.Fastly-Client-IP;
    }
  }

  # Don't cache Authenticate & Authorization
  if (req.http.Authenticate || req.http.Authorization) {
   set req.http.x-pass = "1";
  }

  # Micro-optimization: Always pass these paths directly to php without caching
  # to prevent hashing and cache lookup overhead
  if (req.url.path ~ "(?i)^/(typo3)/") {
    set req.http.x-pass = "1";
  }

  # TYPO3: Admin panel should work
  if (req.url ~ "TSFE_ADMIN_PANEL") {
    set req.http.x-pass = "1";
  }

  # TODO: Validate the cookie value to avoid cache poisoning. For now, we just pass if the cookie is present.
  if (req.http.cookie:be_typo_user) {
    set req.http.x-pass = "1";
  }

  # Disable stale_while_revalidate feature on SHIELD node to avoid caching issue when both soft-purges and shieding are used.
  if (fastly.ff.visits_this_service > 0) {
    set req.max_stale_while_revalidate = 0s;
  }

#FASTLY recv

  # Only GET/HEAD (plus Fastly's purge method) are cacheable; everything else passes.
  if (req.method != "HEAD" && req.method != "GET" && req.method != "FASTLYPURGE") {
    return(pass);
  }

  return(lookup);
}

sub vcl_hash {
  set req.hash += req.url;
  set req.hash += req.http.host;
#FASTLY hash
  return(hash);
}

sub vcl_hit {
#FASTLY hit
  return(deliver);
}

sub vcl_miss {
#FASTLY miss
  #official workaround to avoid the double X-Forwarded-Host when shielding is enabled
  #https://www.fastly.com/documentation/reference/http/http-headers/X-Forwarded-Host/#overriding-multiple-entries
  set bereq.http.X-Forwarded-Host = req.http.host;

  return(fetch);
}

sub vcl_pass {
#FASTLY pass
  #official workaround to avoid the double X-Forwarded-Host when shielding is enabled
  #https://www.fastly.com/documentation/reference/http/http-headers/X-Forwarded-Host/#overriding-multiple-entries
  set bereq.http.X-Forwarded-Host = req.http.host;

  return(pass);
}

sub vcl_fetch {

#FASTLY fetch
  # Record restart count for debugging.
  if (req.restarts > 0) {
    set beresp.http.Fastly-Restarts = req.restarts;
  }

  # handle 5XX (or any other unwanted status code)
  if (beresp.status >= 500 && beresp.status < 600) {

    # deliver stale if the object is available
    if (stale.exists) {
      return(deliver_stale);
    }

    if (req.restarts < 3 && (req.method == "GET" || req.method == "HEAD")) {
      restart;
    }
  }

  # Optimized images are public and identical per URL; cookies and Vary only hurt
  # cacheability here.
  if (req.http.x-fastly-imageopto-api) {
    unset beresp.http.Set-Cookie;
    unset beresp.http.Vary;

    # Apply a longer default TTL for images when the origin provides none.
    if (!beresp.http.Expires && beresp.http.Surrogate-Control !~ "max-age" && beresp.http.Cache-Control !~ "(?:s-maxage|max-age)") {
      set beresp.ttl = 2592000s; # 30 days
      set beresp.http.Cache-Control = "max-age=2592000, public";
    }
  }

  # Pass immediately if x-pass is present
  if (req.http.x-pass) {
    return (pass);
  }

  # remove set cookie headers to make responses cachable
  if (beresp.http.cache-control ~ "public") {
    unset beresp.http.set-cookie;
  }

  # If the response is setting a cookie, make sure it is not cached
  if (beresp.http.set-cookie) {
    return(pass);
  }

  # By default we set a TTL based on the `Cache-Control` header but we don't parse additional directives
  # like `private` and `no-store`. Private in particular should be respected at the edge:
  if (beresp.http.Cache-Control ~ "(?:private|no-cache|no-store)") {
    set req.http.Fastly-Cachetype = "PRIVATE";
    return(pass);
  }

  # Default TTL when the origin provides none. Image responses set their own
  # (longer) default in image_optimizer.vcl, so leave those alone here.
  if (!req.http.X-Fastly-Imageopto-Api && !beresp.http.Expires && beresp.http.Surrogate-Control !~ "max-age" && beresp.http.Cache-Control !~ "(?:s-maxage|max-age)") {
    set beresp.ttl = 3600s;
  }

  if (beresp.http.Surrogate-Control ~ "content=%22ESI/1.0%22" || beresp.http.X-Esi) {
    set beresp.do_esi = true;
  }

  # Stale-while-revalidate and grace mode.
  #
  # Serve slightly stale content while a fresh copy is fetched in the background,
  # and keep serving stale content when the origin is unreachable. Reached only for
  # cacheable responses (caching.vcl passes uncacheable ones first).
  set beresp.stale_while_revalidate = 60s;
  set beresp.stale_if_error = 86400s;       # 24h
  set beresp.grace = 86400s;                # 24h

  return(deliver);
}

sub vcl_error {

  if (obj.status == 810 && obj.response == "Force HTTPS") {
    set obj.status = 301;
    set obj.response = "Moved Permanently";
    set obj.http.Location = "https://" req.http.host req.url;
    synthetic {""};
    return (deliver);
  }

  # Deliver a custom synthetic response body for DDoS mitigated requests
  if (obj.status == 429 && obj.response == "DDOS Attack Mitigated") {
    set obj.http.Content-Type = "text/plain; charset=utf-8";
    synthetic "Request blocked by DDoS Protection.";
    return (deliver);
  }

#FASTLY error

  # handle 5XX (or any other unwanted status code)
  if (obj.status >= 500 && obj.status < 600) {

    # deliver stale object if it is available
    if (stale.exists) {

      return(deliver_stale);
    }
  }

  return(deliver);
}

sub vcl_deliver {
#FASTLY deliver
  # Remove the exact PHP Version from the response for more security (e.g. 404 pages)
  unset resp.http.x-powered-by;
  unset resp.http.server;

  set resp.http.X-Compress-Hint = "on";

  return(deliver);
}

sub vcl_log {
#FASTLY log
}
