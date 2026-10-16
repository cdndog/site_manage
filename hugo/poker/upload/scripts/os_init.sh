#!/bin/bash
set -euo pipefail

# ============================================================
# os_init.sh — Install & configure all tools for HF Space dev
# Extracted from Dockerfile; run on a fresh Ubuntu 22.04 system.
# ============================================================

SYSTEM_DEPS=(
    ca-certificates
    curl
    git
    git-lfs
    gnupg
    python3
    python3-pip
    python3-venv
    sudo
)

echo "[os_init] Updating system packages..."
apt-get update
apt-get install -y --no-install-recommends "${SYSTEM_DEPS[@]}"
apt-get clean
rm -rf /var/lib/apt/lists/*

echo "[os_init] Installing Node.js 22..."
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt-get install -y nodejs
apt-get clean
rm -rf /var/lib/apt/lists/*

echo "[os_init] Installing opencode..."
npm install -g opencode-ai
npm cache clean --force



curl -sLfO https://github.com/tailwindlabs/tailwindcss/releases/download/v3.4.17/tailwindcss-linux-x64
chmod +x tailwindcss-linux-x64
mv tailwindcss-linux-x64 /usr/local/bin/tailwindcss

curl -sLfO https://github.com/gohugoio/hugo/releases/download/v0.157.0/hugo_extended_0.157.0_linux-amd64.tar.gz
tar -zxvf hugo_extended_0.157.0_linux-amd64.tar.gz
chmod +x hugo
mv hugo /usr/local/bin/hugo

rm -f README.md LICENSE hugo_extended_0.157.0_linux-amd64.tar.gz

# echo "[os_init] Installing code-server (VS Code in browser)..."
# curl -fsSL https://code-server.dev/install.sh | sh

# echo "[os_init] Creating user (UID 1000)..."
# useradd -m -u 1000 user
# echo "user ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers
# mkdir -p /home/user/app

echo "[os_init] Done. Set environment variables GITHUB_TOKEN, GITHUB_USER, GITHUB_EMAIL at runtime."
echo "[os_init] Run: entrypoint.sh to start code-server on port 7860."

if [ ! -d "${HOME}/.config/opencode" ]; then
    echo "[os_init] Creating ${HOME}/.config/opencode ..."
    mkdir -p "${HOME}/.config/opencode"
fi

cat <<EOF > "${HOME}/.config/opencode/opencode.jsonc"
{
  "\$schema": "https://opencode.ai/config.json",
  "permission": {
    "external_directory": {
      "/tmp/**": "allow"
    },
    "bash": {
      "*": "allow"
    }
  }
}
EOF

npx -y skills add https://github.com/coreyhaines31/marketingskills --skill seo-audit -y
npx -y skills add https://github.com/wshobson/agents --skill tailwind-design-system -y
npx -y skills add https://github.com/rivet-dev/skills --skill multiplayer-game -y
npx -y skills add https://github.com/currents-dev/playwright-best-practices-skill --skill playwright-best-practices -y 
npx -y skills add https://github.com/microsoft/playwright-cli --skill playwright-cli -y
npx -y skills add https://github.com/heroui-inc/heroui --skill heroui-react -y
npx -y skills add https://github.com/heroui-inc/heroui --skill heroui-native -y
npx -y skills add https://github.com/heroui-inc/heroui --skill heroui-migration -y
