"""Fingerprint reading positions in the isolated, restored Kavita 0.9.1 database."""
import hashlib
import json
import sqlite3
import sys

database = sqlite3.connect("file:/kavita/config/kavita.db?mode=ro", uri=True)
if database.execute("PRAGMA quick_check").fetchall() != [("ok",)]:
    sys.exit("La base de datos restaurada no supera quick_check.")

digest = hashlib.sha256()
count = 0
for row in database.execute(
    "SELECT AppUserId, ChapterId, PagesRead, BookScrollId FROM AppUserProgresses ORDER BY Id"
):
    digest.update(json.dumps(row).encode())
    count += 1
if not count:
    sys.exit("La copia carece de progreso. Avanza en un libro y crea otra copia.")
print(digest.hexdigest())
