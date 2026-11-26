#!/usr/bin/env bash
#
# Pruebas de concurrencia REAL: varios procesos a la vez contra MySQL/MariaDB.
# Detectan lo que la suite normal no puede ver (bloqueos mutuos, números duplicados, stock negativo, doble emisión).
#
#   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=facturador_test DB_USERNAME=... DB_PASSWORD=... \
#     bash tests/Concurrencia/correr.sh
#
# ¡Borra y recrea la base de datos indicada! Úsalo solo con una base de pruebas.
# Termina con código distinto de 0 si algún resultado no es el esperado.
set -uo pipefail
cd "$(dirname "$0")/../.."

export QUEUE_CONNECTION=sync
PROCESOS="${PROCESOS:-8}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
FALLAS=0

reiniciar() { php artisan migrate:fresh --seed --force --no-interaction > /dev/null || { echo "No se pudo preparar la base de datos"; exit 2; }; }

# Lanza el mismo script en $PROCESOS procesos a la vez y espera a todos
en_paralelo() {
    rm -f "$TMP"/p_*.log
    local pids=()
    for i in $(seq 1 "$PROCESOS"); do php "$@" > "$TMP/p_$i.log" 2>&1 & pids+=("$!"); done
    for pid in "${pids[@]}"; do wait "$pid" || true; done
}

# Cualquier excepción inesperada (deadlock, número duplicado...) es una falla
sin_errores() {
    if grep -hE "Deadlock|Exception|ERROR|err=[1-9]|errores=[1-9]" "$TMP"/p_*.log | cut -c1-200 | sort | uniq -c | head -5 | grep -q .; then
        echo "  ✗ hubo errores inesperados:"; grep -hE "Deadlock|Exception|ERROR|err=[1-9]|errores=[1-9]" "$TMP"/p_*.log | cut -c1-200 | sort | uniq -c | head -5
        FALLAS=$((FALLAS + 1))
    else
        echo "  ✓ sin errores inesperados"
    fi
}

verificar() { php tests/Concurrencia/verificar.php "$@" || FALLAS=$((FALLAS + 1)); }

php tests/Concurrencia/verificar.php preparar-stock > /dev/null 2>&1; [ $? -eq 2 ] && { php tests/Concurrencia/verificar.php preparar-stock; exit 2; }

echo "1. $PROCESOS procesos emiten 25 boletas cada uno sobre la misma serie"
reiniciar
en_paralelo tests/Concurrencia/emitir_en_paralelo.php 25
sin_errores
verificar emision $((PROCESOS * 25))

echo "2. $PROCESOS procesos intentan vender $((PROCESOS * 25)) unidades de un producto que solo tiene 100"
reiniciar
php tests/Concurrencia/verificar.php preparar-stock
en_paralelo tests/Concurrencia/vender_stock_en_paralelo.php 25
sin_errores
verificar stock

echo "3. $PROCESOS procesos emiten EL MISMO borrador (doble clic)"
reiniciar
ID="$(php tests/Concurrencia/doble_emision.php preparar)"
en_paralelo tests/Concurrencia/doble_emision.php emitir "$ID"
sin_errores
verificar doble "$ID"

echo "4. $PROCESOS procesos emiten cada uno una nota de crédito por el total de ese comprobante"
en_paralelo tests/Concurrencia/doble_emision.php nota "$ID"
sin_errores
verificar notas "$ID"

echo
if [ "$FALLAS" -eq 0 ]; then echo "Concurrencia: todo correcto."; else echo "Concurrencia: $FALLAS verificación(es) fallaron."; fi
exit "$FALLAS"
