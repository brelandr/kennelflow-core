#!/usr/bin/env python3
"""Migrate KennelFlow Core includes/ to namespace Landtech\\KennelFlow\\Core and strip LTKF_ class prefix."""
from __future__ import annotations

import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
INCLUDES = os.path.join(ROOT, "includes")
NS = "Landtech\\KennelFlow\\Core"


def ltkf_to_short(ltkf_name: str) -> str:
	assert ltkf_name.startswith("LTKF_")
	parts = ltkf_name[5:].split("_")
	return "".join(p.capitalize() for p in parts)


def pascal_to_kebab(name: str) -> str:
	s = re.sub(r"(.)([A-Z][a-z]+)", r"\1-\2", name)
	s = re.sub(r"([a-z0-9])([A-Z])", r"\1-\2", s)
	return s.lower()


def collect_mapping() -> dict[str, str]:
	mapping: dict[str, str] = {}
	for dirpath, _, filenames in os.walk(INCLUDES):
		for fn in filenames:
			if not fn.startswith("class-ltkf-") or not fn.endswith(".php"):
				continue
			path = os.path.join(dirpath, fn)
			with open(path, "r", encoding="utf-8") as f:
				head = f.read(12000)
			m = re.search(r"\bclass\s+(LTKF_[A-Za-z0-9_]+)\b", head)
			if m:
				old = m.group(1)
				mapping[old] = ltkf_to_short(old)
	return mapping


def inject_namespace(text: str) -> str:
	if re.search(r"^\s*namespace\s+Landtech\\KennelFlow\\Core\s*;", text, re.M):
		return text
	# Strip BOM
	text = text.lstrip("\ufeff")
	if not text.startswith("<?php"):
		raise ValueError("missing php open")
	after = text[len("<?php") :]
	# Preserve file docblock if present
	doc = ""
	body = after
	if after.lstrip().startswith("/**"):
		s = after.lstrip()
		e = s.find("*/")
		if e == -1:
			raise ValueError("unclosed docblock")
		doc = s[: e + 2]
		body = s[e + 2 :]

	body = body.lstrip("\n\r")
	# Keep leading defined ABSPATH block together after namespace? WPCS: namespace then ABSPATH.
	out = "<?php\n"
	if doc:
		out += doc + "\n\n"
	out += f"namespace {NS};\n\n"
	out += body
	return out


def replace_classes(text: str, old_to_new: dict[str, str]) -> str:
	for old in sorted(old_to_new.keys(), key=len, reverse=True):
		new = old_to_new[old]
		text = re.sub(r"\b" + re.escape(old) + r"\b", new, text)
	return text


def process_file(path: str, old_to_new: dict[str, str], new_path: str | None = None) -> None:
	with open(path, "r", encoding="utf-8") as f:
		text = f.read()
	text = inject_namespace(text)
	text = replace_classes(text, old_to_new)
	target = new_path if new_path else path
	os.makedirs(os.path.dirname(target), exist_ok=True)
	with open(target, "w", encoding="utf-8") as f:
		f.write(text)


def main() -> None:
	old_to_new = collect_mapping()
	old_keys = sorted(old_to_new.keys(), key=len, reverse=True)

	# 1) Class files: compute renames
	tasks: list[tuple[str, str]] = []
	for dirpath, _, filenames in os.walk(INCLUDES):
		for fn in filenames:
			if not fn.startswith("class-ltkf-") or not fn.endswith(".php"):
				continue
			old_path = os.path.join(dirpath, fn)
			with open(old_path, "r", encoding="utf-8") as f:
				head = f.read(12000)
			m = re.search(r"\bclass\s+(LTKF_[A-Za-z0-9_]+)\b", head)
			if not m:
				continue
			new_cls = old_to_new[m.group(1)]
			new_fn = "class-" + pascal_to_kebab(new_cls) + ".php"
			new_path = os.path.join(dirpath, new_fn)
			tasks.append((old_path, new_path))

	# Process class files
	done_old: set[str] = set()
	for old_path, new_path in tasks:
		process_file(old_path, old_to_new, new_path)
		done_old.add(old_path)
		if os.path.abspath(old_path) != os.path.abspath(new_path) and os.path.isfile(old_path):
			os.remove(old_path)

	# 2) Other includes PHP (functions, stubs) — skip class-*.php already migrated
	for dirpath, _, filenames in os.walk(INCLUDES):
		for fn in filenames:
			if not fn.endswith(".php") or fn == "index.php":
				continue
			if fn.startswith("class-"):
				continue
			path = os.path.join(dirpath, fn)
			with open(path, "r", encoding="utf-8") as f:
				raw = f.read()
			if "namespace Landtech" in raw:
				continue
			process_file(path, old_to_new)

	# Second pass: any remaining class-ltkf filenames (orphans)
	for dirpath, _, filenames in os.walk(INCLUDES):
		for fn in filenames:
			if fn.startswith("class-ltkf-"):
				raise RuntimeError(f"orphan {fn} in {dirpath}")

	print("Migrated", len(old_to_new), "classes")


if __name__ == "__main__":
	main()
