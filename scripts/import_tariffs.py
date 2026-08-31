#!/usr/bin/env python3
"""Back up and import the multilingual customs tariff catalog into Laravel's MySQL database."""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime
from pathlib import Path
from typing import Any, Iterable

try:
    import mysql.connector
except ModuleNotFoundError:
    sys.exit("Missing dependency. Run: py -m pip install -r backend/scripts/requirements.txt")


SOURCE_COLUMNS = [
    "EX",
    "Tarifna oznaka",
    "Naziv",
    "Odjeljak",
    "Glava",
    "Prethodna tarifna oznaka",
    "Puni Naziv",
    "Puni Naziv - ENG",
    "Puni Naziv - D",
]

DATABASE_COLUMNS = [
    "ex",
    "tariff_code",
    "name",
    "section",
    "chapter",
    "previous_tariff_code",
    "full_name",
    "full_name_en",
    "full_name_de",
]


def parse_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw_line in path.read_text(encoding="utf-8-sig").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def connect(env: dict[str, str]):
    if env.get("DB_CONNECTION", "sqlite") != "mysql":
        raise RuntimeError("This importer currently requires DB_CONNECTION=mysql.")
    return mysql.connector.connect(
        host=env.get("DB_HOST", "127.0.0.1"),
        port=int(env.get("DB_PORT", "3306")),
        database=env["DB_DATABASE"],
        user=env["DB_USERNAME"],
        password=env.get("DB_PASSWORD", ""),
        charset="utf8mb4",
        collation="utf8mb4_unicode_ci",
    )


def sql_literal(value: Any) -> str:
    if value is None:
        return "NULL"
    if isinstance(value, bool):
        return "1" if value else "0"
    if isinstance(value, (int, float)):
        return str(value)
    escaped = str(value).replace("\\", "\\\\").replace("'", "\\'")
    escaped = escaped.replace("\0", "\\0").replace("\n", "\\n").replace("\r", "\\r")
    return f"'{escaped}'"


def chunks(values: list[Any], size: int) -> Iterable[list[Any]]:
    for offset in range(0, len(values), size):
        yield values[offset:offset + size]


def dump_table(connection, destination: Path) -> None:
    cursor = connection.cursor(dictionary=True)
    cursor.execute("SHOW CREATE TABLE hs_code_catalog")
    create_row = cursor.fetchone()
    if not create_row:
        raise RuntimeError("hs_code_catalog does not exist.")
    create_sql = next(value for key, value in create_row.items() if key.lower().startswith("create "))

    cursor.execute("SELECT * FROM hs_code_catalog ORDER BY id")
    rows = cursor.fetchall()
    columns = list(rows[0]) if rows else []
    destination.parent.mkdir(parents=True, exist_ok=True)
    with destination.open("w", encoding="utf-8", newline="\n") as handle:
        handle.write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n")
        handle.write("DROP TABLE IF EXISTS `hs_code_catalog`;\n")
        handle.write(f"{create_sql};\n")
        for batch in chunks(rows, 250):
            names = ", ".join(f"`{name}`" for name in columns)
            values = ",\n".join(
                "(" + ", ".join(sql_literal(row[name]) for name in columns) + ")"
                for row in batch
            )
            handle.write(f"INSERT INTO `hs_code_catalog` ({names}) VALUES\n{values};\n")
        handle.write("SET FOREIGN_KEY_CHECKS=1;\n")
    print(f"Backup written: {destination} ({len(rows)} rows)")


def load_source(path: Path) -> list[dict[str, Any]]:
    data = json.loads(path.read_text(encoding="utf-8-sig"))
    if not isinstance(data, list):
        raise ValueError("Tariff file must contain a JSON array.")

    expected = set(SOURCE_COLUMNS)
    for index, row in enumerate(data, start=1):
        if not isinstance(row, dict) or set(row) != expected:
            actual = set(row) if isinstance(row, dict) else set()
            raise ValueError(
                f"Row {index} columns differ. Missing={sorted(expected - actual)} extra={sorted(actual - expected)}"
            )
    return data


def import_rows(connection, source: list[dict[str, Any]], batch_size: int) -> None:
    cursor = connection.cursor()
    cursor.execute("SHOW COLUMNS FROM hs_code_catalog")
    actual_columns = [row[0] for row in cursor.fetchall()]
    expected_columns = ["id", *DATABASE_COLUMNS]
    if actual_columns != expected_columns:
        raise RuntimeError(
            "Database schema is not ready for this catalog. Run php artisan migrate first. "
            f"Expected {expected_columns}, got {actual_columns}."
        )

    placeholders = ", ".join(["%s"] * len(DATABASE_COLUMNS))
    column_sql = ", ".join(f"`{column}`" for column in DATABASE_COLUMNS)
    insert_sql = f"INSERT INTO hs_code_catalog ({column_sql}) VALUES ({placeholders})"
    values = [tuple(row[column] for column in SOURCE_COLUMNS) for row in source]

    try:
        if not connection.in_transaction:
            connection.start_transaction()
        cursor.execute("DELETE FROM hs_code_catalog")
        for batch in chunks(values, batch_size):
            cursor.executemany(insert_sql, batch)
        connection.commit()
    except Exception:
        connection.rollback()
        raise

    cursor.execute(
        "SELECT COUNT(*), SUM(tariff_code IS NOT NULL), "
        "SUM(CHAR_LENGTH(REGEXP_REPLACE(COALESCE(tariff_code, ''), '[^0-9]', '')) = 10) "
        "FROM hs_code_catalog"
    )
    total, coded, selectable = cursor.fetchone()
    print(f"Imported {total} rows ({coded} coded, {selectable} selectable ten-digit leaves).")


def main() -> None:
    backend = Path(__file__).resolve().parents[1]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--file", type=Path, default=backend / "database/data/Tarife_DE_final.json")
    parser.add_argument("--env", type=Path, default=backend / ".env")
    parser.add_argument("--batch-size", type=int, default=500)
    parser.add_argument("--backup-only", action="store_true")
    parser.add_argument("--skip-backup", action="store_true")
    args = parser.parse_args()

    connection = connect(parse_env(args.env))
    try:
        if not args.skip_backup:
            stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
            dump_table(connection, backend / f"database/backups/hs_code_catalog_{stamp}.sql")
        if not args.backup_only:
            import_rows(connection, load_source(args.file), max(1, args.batch_size))
    finally:
        connection.close()


if __name__ == "__main__":
    main()
