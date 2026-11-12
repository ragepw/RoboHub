#!/usr/bin/env bash
# Privater Offline-Transfer; ohne --apply ausschließlich lokale Vorbereitung.
set -euo pipefail
umask 077
cd -- "$(dirname -- "$0")/.."
commit=$(git rev-parse HEAD^{commit})
base=$(git rev-parse 6fefd79^{commit})
branch=feature/eureka-e20-evo-plus
host=admin@10.10.70.6
mode=${1:---prepare}
[[ $# -le 1 && ( $mode == --prepare || $mode == --apply ) ]] || exit 2
[[ $(git rev-parse "$branch") == "$commit" ]] || { echo 'Branchstand abweichend; Abbruch.'; exit 1; }
git merge-base --is-ancestor "$base" "$commit"
[[ -z $(git status --porcelain) ]] || { echo 'Lokaler Stand nicht vollständig committed; Abbruch.'; exit 1; }
# Außerhalb von Repository und Webverzeichnissen, keine Kennung als Argument.
package=$(mktemp -d "$HOME/private/robohub-deploy.XXXXXXXX")
chmod 700 "$package"
git bundle create "$package/update.bundle" "$branch" "^$base" >/dev/null 2>&1
git bundle verify "$package/update.bundle" >/dev/null 2>&1
chmod 600 "$package/update.bundle"
if [[ $mode == --prepare ]]; then
    echo "Privates Bundle vorbereitet: $package/update.bundle"
    echo 'Produktivinstallation unverändert. Einspielen: bash .tools/deploy-eureka-local.sh --apply'
    exit 0
fi
ssh_opts=(-o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=10)
# Auch die versteckte Store-Installation prüfen, bevor produktiv etwas geändert wird.
ssh "${ssh_opts[@]}" "$host" sudo -n python3 - /var/lib/symcon/modules < .tools/check-robohub-installation.py
# Ausschließlich SSH, kein Git-Remotezugriff und kein HTTP-Dateiserver.
remote=$(ssh "${ssh_opts[@]}" "$host" 'umask 077; mktemp -d /home/admin/robohub-deploy.XXXXXXXX')
[[ $remote == /home/admin/robohub-deploy.* && $remote != *[^a-zA-Z0-9/._-]* ]] || exit 1
trap 'ssh "${ssh_opts[@]}" "$host" "rm -rf -- $remote" >/dev/null 2>&1 || true' EXIT
scp -q "${ssh_opts[@]}" "$package/update.bundle" "$host:$remote/update.bundle"
ssh "${ssh_opts[@]}" "$host" sudo -n bash -s -- "$remote" "$commit" "$base" "$branch" <<'REMOTE'
set -euo pipefail
umask 077
stage=$1
commit=$2
base=$3
branch=$4
repo=/var/lib/symcon/modules/RoboHub
export GIT_CONFIG_COUNT=3
export GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="$repo"
export GIT_CONFIG_KEY_1=core.hooksPath GIT_CONFIG_VALUE_1=/dev/null
export GIT_CONFIG_KEY_2=protocol.allow GIT_CONFIG_VALUE_2=never
[[ -d $repo/.git && ! -L $repo ]]
[[ $(git -C "$repo" rev-parse HEAD) == "$base" ]]
[[ $(git -C "$repo" branch --show-current) == "$branch" ]]
[[ -z $(git -C "$repo" status --porcelain) ]]
systemctl is-active --quiet symcon
work=$(mktemp -d /root/robohub-deploy.XXXXXXXX)
chmod 700 "$work"
install -m 600 "$stage/update.bundle" "$work/update.bundle"
rm -rf -- "$stage"
git -C "$repo" bundle verify "$work/update.bundle" >"$work/verify.log" 2>&1
# Vor dem Austausch alle persistenten Symcon-Daten im gestoppten Zustand sichern.
# Ein Fehler lässt den Dienst bewusst gestoppt: kein Start mit halbem Update.
systemctl stop symcon
tar -C /var/lib -cpf "$work/symcon-before.tar" symcon
chmod 600 "$work/symcon-before.tar"
# Dienst läuft als root. Quelltext und Gitobjekte mit individueller ID privat halten.
chown -R root:root "$repo"
chmod -R go-rwx "$repo"
git -C "$repo" -c protocol.file.allow=always fetch "$work/update.bundle" "$branch" >"$work/update.log" 2>&1
[[ $(git -C "$repo" rev-parse FETCH_HEAD) == "$commit" ]]
git -C "$repo" merge --ff-only FETCH_HEAD >>"$work/update.log" 2>&1
systemctl start symcon
systemctl is-active --quiet symcon
printf 'Lokale Modulaktualisierung abgeschlossen. Private Sicherung: %s/symcon-before.tar\n' "$work"
REMOTE
