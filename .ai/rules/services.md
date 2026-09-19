---
paths:
  - app/Services/ProxyAuthService.php
  - app/Services/ReverseProxyService.php
---

# Services

## Reverse proxy NAS-login architecture
Login-restricted proxy hosts use a token cookie (`novanas_proxy_auth`, excluded from cookie encryption in bootstrap/app.php) validated by Apache `RewriteMap` txt files in /etc/apache2/novanas-proxy-auth (keys = sha256 token hashes). Flow: Apache redirects unauthenticated visitors to the NAS bridge `/proxy-auth/login` (NAS domain, NAS session) which redirects to `https://<proxy-domain>/__novanas_proxy_auth/grant` (proxy domain, served locally via DocumentRoot + `ProxyPass !` exclusion, never proxied) which sets the cookie. The cookie value is the token hash; the plain token only travels once in the grant URL. Map files must exist before `apache2ctl configtest` (ensureAuthMapFile). Keep in mind when touching proxy vhost generation or the bridge routes.

## RewriteMap token files: format and permissions
Apache RewriteMap txt files must be world-readable (tempnam files are 0600 - run `sudo chmod 644` after `sudo cp`) and each line must be `key value` (bare keys fail the lookup silently). In RewriteRule substitutions never use a zero-width pattern like `^` with a URL substitution (mod_rewrite appends the unmatched URI remainder) and never use the `${escape:...}` builtin (returns garbage); use `RewriteRule .*` + `[QSA]` instead.

## Proxy login bridge must bypass the .htaccess restart
In the proxy vhosts, the login-bridge path must be resolved to the front controller IN THE VHOST PHASE (`RewriteRule ^\/__novanas_proxy_auth /index.php [L]`). Using a no-op `- [L]` rule lets public/.htaccess do the front-controller rewrite, whose internal redirect re-runs the vhost rewrite rules and bounces the grant request back to the NAS login (request never reaches Laravel). Same class of bug: never use zero-width `^` patterns with URL substitutions (remainder append) or `${escape:...}` (broken in substitutions).

## Proxy auth token security model
Token map files must be `chown root:$APACHE_RUN_GROUP` + `chmod 640` (group read from /etc/apache2/envvars, NOT hardcoded www-data - this install runs Apache workers as `glados`). Grant URLs carry only a single-use 2-minute nonce (`proxy_auth_tokens.grant_nonce`); the cookie capability is the sha256 token hash which never travels in a URL. Bridge issuance rewrites the map (one token row per user, replaced on each bridge pass).
