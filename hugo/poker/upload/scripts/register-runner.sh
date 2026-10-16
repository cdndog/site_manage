#!/bin/bash
# =====================================================================
# register-runner.sh — 自托管 GitHub Actions runner 一键自动化注册
#
# 前置条件：
#   - GITHUB_TOKEN 必须是 PAT（Personal Access Token），不是 Actions 自动
#     生成的 GITHUB_TOKEN（后者无权限注册 runner）。
#     经典 PAT：勾选 repo 权限；Fine-grained PAT：仓库 Administration read/write。
#     令牌持有者必须是仓库（或组织）管理员。
#   - 主机需已装：curl、git；systemd 主机建议 sudo。
#
# 用法（在 runner 主机上直接执行，或经 SSH 远程执行）：
#   GITHUB_TOKEN=<PAT> REPO=owner/repo ./scripts/register-runner.sh
#
# 可选环境变量：
#   REPO          必填，格式 owner/repo
#   RUNNER_NAME   可选，默认 <主机名>-runner
#   LABELS        可选，默认 self-hosted,linux,x64
#   RUNNER_WORK   可选，工作目录名，默认 _work
#   RUNNER_VERSION 可选，默认自动取 GitHub 最新版
#   RUNNER_DIR    可选，默认 ${HOME}/actions-runner
#   GITHUB_HOST   可选，默认 https://github.com（GitHub Enterprise 时改为 https://<域名>）
# =====================================================================
set -euo pipefail

GITHUB_TOKEN="${GITHUB_TOKEN:-}"
REPO="${REPO:-}"
RUNNER_NAME="${RUNNER_NAME:-$(hostname)-runner}"
LABELS="${LABELS:-self-hosted,linux,x64}"
RUNNER_WORK="${RUNNER_WORK:-_work}"
RUNNER_VERSION="${RUNNER_VERSION:-}"
RUNNER_DIR="${RUNNER_DIR:-${HOME}/actions-runner}"
GITHUB_HOST="${GITHUB_HOST:-https://github.com}"

[ -n "$GITHUB_TOKEN" ] || { echo "错误：缺少 GITHUB_TOKEN（必须是 PAT）" >&2; exit 1; }
[ -n "$REPO" ] || { echo "错误：缺少 REPO（格式 owner/repo）" >&2; exit 1; }

case "$GITHUB_HOST" in
  https://github.com) API="https://api.github.com"; URL="$GITHUB_HOST/$REPO" ;;
  *) API="$GITHUB_HOST/api/v3"; URL="$GITHUB_HOST/$REPO" ;;
esac

case "$(uname -m)" in
  x86_64) ARCH=x64 ;;
  aarch64|arm64) ARCH=arm64 ;;
  *) echo "错误：不支持的架构 $(uname -m)" >&2; exit 1 ;;
esac

command -v curl >/dev/null || { echo "错误：缺少 curl" >&2; exit 1; }
command -v git >/dev/null || { echo "错误：缺少 git" >&2; exit 1; }
if ! command -v envsubst >/dev/null 2>&1; then
  echo "==> 安装 gettext-base（svc.sh 依赖 envsubst）"
  sudo apt-get install -y --no-install-recommends gettext-base >/dev/null
fi

if [ -z "$RUNNER_VERSION" ]; then
  echo "==> 获取 runner 最新版本"
  RUNNER_VERSION=$(curl -fsSL "$API/repos/actions/runner/releases/latest" \
    | sed -n 's/.*"tag_name": *"v\([^"]*\)".*/\1/p' | head -1)
  [ -n "$RUNNER_VERSION" ] || { echo "错误：无法获取 runner 版本（网络或 API 受限）" >&2; exit 1; }
fi

echo "==> 获取注册 token（有效期 1 小时）"
REG_TOKEN=$(curl -fsSL -X POST \
  -H "Authorization: Bearer $GITHUB_TOKEN" \
  -H "Accept: application/vnd.github+json" \
  "$API/repos/$REPO/actions/runners/registration-token" \
  | sed -n 's/.*"token": *"\([^"]*\)".*/\1/p')
if [ -z "$REG_TOKEN" ]; then
  echo "错误：获取注册 token 失败。请确认 PAT 具备 Administration 权限且你有仓库管理员身份" >&2
  exit 1
fi

echo "==> 下载 runner v$RUNNER_VERSION ($ARCH)"
TARBALL="actions-runner-linux-$ARCH-$RUNNER_VERSION.tar.gz"
curl -fsSL -o "/tmp/$TARBALL" "$GITHUB_HOST/actions/runner/releases/download/v$RUNNER_VERSION/$TARBALL"
mkdir -p "$RUNNER_DIR"
tar -xzf "/tmp/$TARBALL" -C "$RUNNER_DIR"
rm -f "/tmp/$TARBALL"

echo "==> 配置 runner（名称: $RUNNER_NAME | 标签: $LABELS）"
cd "$RUNNER_DIR"
if ! ./config.sh --unattended --replace --url "$URL" --token "$REG_TOKEN" \
     --name "$RUNNER_NAME" --labels "$LABELS" --work "$RUNNER_WORK"; then
  echo "==> 配置失败，清理旧配置后重试"
  rm -f .runner
  ./config.sh --unattended --replace --url "$URL" --token "$REG_TOKEN" \
     --name "$RUNNER_NAME" --labels "$LABELS" --work "$RUNNER_WORK"
fi

echo "==> 安装为服务"
if command -v systemctl >/dev/null 2>&1; then
  sudo ./svc.sh install
  sudo ./svc.sh start
else
  nohup ./run.sh > runner.log 2>&1 &
  echo "无 systemd，已用 nohup 后台启动，日志: $RUNNER_DIR/runner.log"
fi

echo
echo "============================================================"
echo "注册完成："
echo "  - 名称 : $RUNNER_NAME"
echo "  - 仓库 : $REPO"
echo "  - 标签 : $LABELS"
echo "  - 验证 : 仓库 Settings -> Actions -> Runners；或 API："
echo "      curl -H \"Authorization: Bearer <PAT>\" $API/repos/$REPO/actions/runners"
echo "  - 卸载 : sudo $RUNNER_DIR/svc.sh uninstall && cd $RUNNER_DIR && ./config.sh remove"
echo "============================================================"
