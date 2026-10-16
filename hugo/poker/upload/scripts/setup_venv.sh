#!/bin/bash
set -euo pipefail

VENV_DIR="${1:-venv}"

# ── Detect Python ─────────────────────────────────────────────────────────────
PYTHON=""
for cmd in python3 python; do
    if command -v "$cmd" &>/dev/null; then
        PYTHON="$cmd"
        break
    fi
done

if [ -z "$PYTHON" ]; then
    echo "[ERR] 未找到 Python，请先安装 python3"
    exit 1
fi

echo "[setup] 使用: $($PYTHON --version)"

# ── Create venv ───────────────────────────────────────────────────────────────
if [ -d "$VENV_DIR" ]; then
    echo "[setup] venv 已存在: $VENV_DIR"
else
    echo "[setup] 创建 venv → $VENV_DIR"
    "$PYTHON" -m venv "$VENV_DIR"
fi

# ── Activate & upgrade pip ────────────────────────────────────────────────────
source "$VENV_DIR/bin/activate"
echo "[setup] pip: $(pip --version)"

# ── Install dependencies ──────────────────────────────────────────────────────
echo "[setup] 安装依赖: requests beautifulsoup4"
pip install -q --upgrade pip
pip install -q requests beautifulsoup4

echo ""
echo "  ✓ venv 就绪: $VENV_DIR"
echo "  ✓ 使用命令激活: source $VENV_DIR/bin/activate"
echo "  ✓ 运行脚本: python wayback-archiver.py <URL>"
echo ""
