#!/usr/bin/env bash
#
# Provado signal shipper — New Relic Event API. No New Relic extension required.
# Reads Magento operational state read-only and POSTs a ProvadoSignal custom event.
# Schedule from cron (e.g. */5 * * * *).
#
#   NR_ACCOUNT_ID=...  NR_INSERT_KEY=...  ./provado-ship.sh
#
# NR_INSERT_KEY is a New Relic Insert/License key. EU accounts: use
# https://insights-collector.eu01.nr-data.net/...
set -euo pipefail

: "${NR_ACCOUNT_ID:?set NR_ACCOUNT_ID}"
: "${NR_INSERT_KEY:?set NR_INSERT_KEY}"
SOURCE="${PROVADO_SOURCE:-magento}"
COLLECTOR="${NR_COLLECTOR:-https://insights-collector.newrelic.com}"

# Configure read-only MySQL access (e.g. a ~/.my.cnf or MYSQL_PWD + flags).
MYSQL=(mysql -N -B "${MYSQL_DB:-magento}")

# signal: cron_health — one row of named counts.
read -r pending running success missed error < <(
  "${MYSQL[@]}" -e "SELECT
      SUM(status='pending'), SUM(status='running'), SUM(status='success'),
      SUM(status='missed'),  SUM(status='error')
    FROM cron_schedule"
)

curl -fsS -X POST "${COLLECTOR}/v1/accounts/${NR_ACCOUNT_ID}/events" \
  -H "Api-Key: ${NR_INSERT_KEY}" \
  -H "Content-Type: application/json" \
  -d "[{\"eventType\":\"ProvadoSignal\",\"signal\":\"cron_health\",\"source\":\"${SOURCE}\",\"pending\":${pending:-0},\"running\":${running:-0},\"success\":${success:-0},\"missed\":${missed:-0},\"error\":${error:-0}}]"
