#!/usr/bin/env bash
# Test the FTP (FTPS) login from .env. Read-only: logs in, prints the folder and lists it. Uploads nothing.
#
#   ./test_ftp.sh
#
# .env keys: USER, PASS, SERVER_NAME (values read literally: surrounding quotes removed, nothing expanded)
# Needs lftp:  sudo dnf install lftp   (Debian/Ubuntu: sudo apt install lftp)
set -euo pipefail
cd "$(dirname "$0")"

command -v lftp >/dev/null || { echo "lftp is not installed. Install it with: sudo dnf install lftp" >&2; exit 1; }
[[ -f .env ]] || { echo "Missing .env" >&2; exit 1; }

# ---------- read .env literally (same rules as deploy.sh) ----------
declare -A ENV=()
while IFS= read -r line || [[ -n "$line" ]]; do
  line="${line%$'\r'}"
  [[ -z "${line// }" || "$line" =~ ^[[:space:]]*# ]] && continue
  [[ "$line" == *=* ]] || continue
  key="${line%%=*}"; key="${key//[[:space:]]/}"; key="${key#export}"
  val="${line#*=}"
  val="${val#"${val%%[![:space:]]*}"}"; val="${val%"${val##*[![:space:]]}"}"
  if [[ ${#val} -ge 2 && ( ( "${val:0:1}" == '"' && "${val: -1}" == '"' ) || ( "${val:0:1}" == "'" && "${val: -1}" == "'" ) ) ]]; then
    val="${val:1:${#val}-2}"
  fi
  ENV["$key"]="$val"
done < .env

FTP_USER="${ENV[USER]:-}"
FTP_HOST="${ENV[SERVER_NAME]:-}"
[[ -n "$FTP_USER" && -n "$FTP_HOST" && -n "${ENV[PASS]:-}" ]] || { echo ".env needs USER, PASS and SERVER_NAME" >&2; exit 1; }

# lftp reads the password from LFTP_PASSWORD (--env-password), so it never appears in the process list.
export LFTP_PASSWORD="${ENV[PASS]}"

echo "Testing FTPS login as $FTP_USER@$FTP_HOST …"
# Only one try (max-retries 1) so a wrong password doesn't trigger the server's lockout.
if out="$(lftp -c "
    set ftp:ssl-force true;
    set ssl:verify-certificate true;
    set net:max-retries 1;
    set net:timeout 30;
    open --env-password -u \"$FTP_USER\" ftp://$FTP_HOST;
    echo LOGIN_OK;
    pwd;
    cls -la --sort=name;
" 2>&1)"; then
  echo "$out" | sed '1s/^LOGIN_OK$/✓ Login OK/'
  echo
  echo "FTP works. The folder above is where the login lands (what REMOTE_DIR=\"/\" means)."
  exit 0
else
  echo "$out" | grep -vi 'password' | tail -5
  echo
  echo "✗ FTP login failed. Check USER/PASS in .env (the password is used exactly as written between the quotes)." >&2
  exit 1
fi
