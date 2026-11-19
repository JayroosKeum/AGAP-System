#!/bin/bash
set -euo pipefail

APP_ROOT="/home/site/wwwroot"
STORAGE_ROOT="${APP_ROOT}/storage"
NGINX_INCLUDE="${APP_ROOT}/azure/nginx-security.conf"

# Ensure mounted Azure Files storage subdirectories exist on boot.
for dir in \
    backups \
    certs \
    documents \
    documents/kp-forms \
    exports \
    generated-documents \
    generated-reports \
    logs \
    paanyaya \
    proofs \
    summons-proofs \
    uploads \
    uploads/branding \
    uploads/complaint-evidence \
    uploads/complaint-scans \
    uploads/complaints \
    uploads/evidence \
    uploads/forms \
    uploads/proof-of-service \
    uploads/seals \
    uploads/signatures \
    uploads/templates; do
    mkdir -p "${STORAGE_ROOT}/${dir}" || true
done

# Apply AGAP Nginx security & upload-size rules on the Azure Linux PHP 8.3 image.
if [ -f "${NGINX_INCLUDE}" ]; then
    for nginx_site in /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default; do
        if [ -f "${nginx_site}" ] && [ ! -L "${nginx_site}" ] || [ "${nginx_site}" = "/etc/nginx/sites-available/default" ]; then
            if [ -f "${nginx_site}" ] && ! grep -Fq "${NGINX_INCLUDE}" "${nginx_site}"; then
                awk -v inc="    include ${NGINX_INCLUDE};" '
                    !done && /server_name[[:space:]]+/ {
                        print
                        print inc
                        done = 1
                        next
                    }
                    { print }
                ' "${nginx_site}" > "${nginx_site}.tmp"
                mv "${nginx_site}.tmp" "${nginx_site}"
            fi
        fi
    done

    if command -v nginx >/dev/null 2>&1 && nginx -t >/dev/null 2>&1; then
        service nginx reload >/dev/null 2>&1 || true
    fi
fi
