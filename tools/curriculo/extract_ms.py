#!/usr/bin/env python3
"""Extrai habilidades dos PDFs oficiais da SED/MS para CSV revisavel.

Uso:
    python tools/curriculo/extract_ms.py --ef caminho/ef.pdf --em caminho/em.pdf \
        --output apps/apc/resources/curriculo

O script e uma ferramenta de desenvolvimento. A aplicacao APC nao depende de
Python nem de acesso a internet em producao.
"""

from __future__ import annotations

import argparse
import csv
import re
import unicodedata
from collections import defaultdict
from dataclasses import dataclass, field
from pathlib import Path
from typing import Iterable

import pdfplumber


CSV_FIELDS = [
    "etapa",
    "anos_series",
    "tipo_associacao",
    "componente",
    "sigla",
    "codigo",
    "descricao",
    "unidade_tematica",
    "objeto_conhecimento",
    "origem",
    "escopo",
    "fonte_documento",
    "fonte_pagina",
]

EF_COMPONENTS = {
    "LINGUA PORTUGUESA": ("Língua Portuguesa", "LP"),
    "ARTE": ("Arte", "ARTE"),
    "EDUCACAO FISICA": ("Educação Física", "EDF"),
    "LINGUA INGLESA": ("Língua Inglesa", "LI"),
    "LINGUA ESPANHOLA": ("Língua Espanhola", "LE"),
    "MATEMATICA": ("Matemática", "MAT"),
    "CIENCIAS": ("Ciências", "CIE"),
    "GEOGRAFIA": ("Geografia", "GEO"),
    "HISTORIA": ("História", "HIS"),
    "ENSINO RELIGIOSO": ("Ensino Religioso", "ER"),
}

EM_COMPONENTS = {
    "ARTE": ("Arte", "ARTE"),
    "EDUCACAO FISICA": ("Educação Física", "EDF"),
    "LINGUA INGLESA": ("Língua Inglesa", "LI"),
    "LINGUA ESPANHOLA": ("Língua Espanhola", "LE"),
    "LINGUA PORTUGUESA": ("Língua Portuguesa", "LP"),
    "MATEMATICA": ("Matemática", "MAT"),
    "FISICA": ("Física", "FIS"),
    "QUIMICA": ("Química", "QUI"),
    "BIOLOGIA": ("Biologia", "BIO"),
    "GEOGRAFIA": ("Geografia", "GEO"),
    "HISTORIA": ("História", "HIS"),
    "FILOSOFIA": ("Filosofia", "FIL"),
    "SOCIOLOGIA": ("Sociologia", "SOC"),
}


@dataclass
class Heading:
    position: float
    page: int
    section: str
    year: str
    raw: str


@dataclass
class TextLine:
    position: float
    page: int
    top: float
    text: str


@dataclass
class CodeEvent:
    position: float
    page: int
    top: float
    code: str
    first_text: str
    heading: Heading
    skill_lines: list[TextLine]
    component_lines: list[TextLine]
    object_lines: list[TextLine]
    unit_lines: list[TextLine]


@dataclass
class CurriculumRow:
    etapa: str
    years: list[str]
    component: str
    sigla: str
    code: str | None
    description: str
    unit: str = ""
    object: str = ""
    source_page: int | None = None
    association: str = "CURRICULAR"
    origin: str = ""
    scope: str = "CURRICULO_COMPLETO"
    source_document: str = ""

    def as_csv(self) -> dict[str, str]:
        return {
            "etapa": self.etapa,
            "anos_series": "|".join(self.years),
            "tipo_associacao": self.association,
            "componente": self.component,
            "sigla": self.sigla,
            "codigo": self.code or "",
            "descricao": self.description,
            "unidade_tematica": self.unit,
            "objeto_conhecimento": self.object,
            "origem": self.origin,
            "escopo": self.scope,
            "fonte_documento": self.source_document,
            "fonte_pagina": "" if self.source_page is None else str(self.source_page),
        }


def folded(value: str) -> str:
    # NFKD transforma o indicador ordinal masculino em "o"; preserve-o para
    # não confundir "1º ano" com texto comum durante a leitura dos títulos.
    value = value.replace("º", "__ORD__").replace("°", "__ORD__")
    normalized = unicodedata.normalize("NFKD", value)
    return "".join(char for char in normalized if not unicodedata.combining(char)).upper().replace("__ORD__", "º")


def clean(value: str) -> str:
    value = value.replace("\u00ad", "").replace("\uf0b7", "•")
    value = re.sub(r"\s+", " ", value).strip()
    # Hifen colocado apenas para quebra de linha pelo PDF.
    value = re.sub(r"(?<=\w)-\s+(?=[a-záàâãéêíóôõúç])", "", value)
    return value.strip()


def lines(words: Iterable[dict], x0: float, x1: float, page_number: int, page_height: float) -> list[TextLine]:
    selected = [word for word in words if word["x0"] >= x0 and word["x0"] < x1 and 55 <= word["top"] <= page_height - 35]
    selected.sort(key=lambda word: (word["top"], word["x0"]))
    groups: list[list[dict]] = []
    for word in selected:
        if not groups or abs(groups[-1][0]["top"] - word["top"]) > 2.0:
            groups.append([word])
        else:
            groups[-1].append(word)
    result = []
    for group in groups:
        group.sort(key=lambda word: word["x0"])
        text = clean(" ".join(word["text"] for word in group))
        if text:
            top = min(word["top"] for word in group)
            result.append(TextLine((page_number - 1) * 1000 + top, page_number, top, text))
    return result


def full_lines(words: Iterable[dict], page_number: int, page_height: float) -> list[TextLine]:
    return lines(words, 70, 550, page_number, page_height)


def parse_ef_heading(line: TextLine) -> Heading | None:
    text = clean(line.text).replace("–", "-")
    match = re.match(r"^(.+?)\s+-\s+(.+?\bANOS?\b)$", text, re.IGNORECASE)
    if not match:
        return None
    component_key = folded(match.group(1))
    if component_key not in EF_COMPONENTS:
        return None
    year = folded(match.group(2)).replace("°", "º")
    if not re.search(r"[1-9]º", year):
        return None
    return Heading(line.position, line.page, component_key, year, line.text)


def parse_em_heading(line: TextLine) -> Heading | None:
    text = clean(line.text).replace("–", "-")
    match = re.match(r"^(.+?)\s+-\s+([123][º°]\s+ANO(?:\s+DO\s+ENSINO\s+MEDIO|\s+EM)?)$", folded(text))
    if not match:
        return None
    section = match.group(1).strip()
    aliases = {
        "LINGUAGENS E SUAS TECNOLOGIAS": "LINGUAGENS",
        "LINGUA PORTUGUESA": "LINGUA PORTUGUESA",
        "MATEMATICA E SUAS TECNOLOGIAS": "MATEMATICA",
        "CIENCIAS HUMANAS E SOCIAIS APLICADAS": "CIENCIAS HUMANAS",
        "CIENCIAS DA NATUREZA E SUAS TECNOLOGIAS": "CIENCIAS DA NATUREZA",
    }
    if section not in aliases:
        return None
    year_digit = re.search(r"[123]", match.group(2)).group(0)
    return Heading(line.position, line.page, aliases[section], f"EM{year_digit}", line.text)


def ef_years(label: str) -> list[str]:
    numbers = [int(value) for value in re.findall(r"([1-9])º", label)]
    if not numbers:
        raise ValueError(f"Ano/série não reconhecido: {label}")
    if " AO " in f" {label} " and len(numbers) == 2:
        numbers = list(range(numbers[0], numbers[1] + 1))
    return [f"EF{number}" for number in numbers]


def nearest_heading(headings: list[Heading], position: float) -> Heading | None:
    found = None
    for heading in headings:
        if heading.position <= position + 2:
            found = heading
        else:
            break
    return found


def content_between(all_lines: list[TextLine], start: float, end: float, excluded_positions: set[float] | None = None) -> str:
    excluded_positions = excluded_positions or set()
    chunks = []
    for line in all_lines:
        if not (start <= line.position < end):
            continue
        if any(abs(line.position - value) < 2.1 for value in excluded_positions):
            continue
        normalized = folded(line.text)
        if normalized in {"HABILIDADES", "HABILIDADE", "OBJETOS DE CONHECIMENTO", "OBJETO DE CONHECIMENTO", "UNIDADES TEMATICAS", "PRATICAS DE LINGUAGEM", "EIXO TEMATICO", "COMPONENTE CURRICULAR", "SUGESTOES DIDATICAS", "ACOES DIDATICAS"}:
            continue
        if re.fullmatch(r"\d+", line.text):
            continue
        text=line.text
        header=r"(?:Habilidades?|Objetos? de Conhecimento|Unidades Temáticas|Práticas de Linguagem|Componente Curricular|Sugestões Didáticas|Ações Didáticas)"
        text=re.sub(rf"^{header}\s+", "", text, flags=re.IGNORECASE)
        text=re.sub(rf"\s+{header}$", "", text, flags=re.IGNORECASE)
        if text:
            chunks.append(text)
    return clean(" ".join(chunks))


def code_events(
    headings: list[Heading],
    skill_lines: list[TextLine],
    component_lines: list[TextLine],
    object_lines: list[TextLine],
    unit_lines: list[TextLine],
) -> list[CodeEvent]:
    events = []
    pattern = re.compile(r"^\((MS\s*\.\s*[^)]+)\)\s*(.*)$", re.IGNORECASE)
    for line in skill_lines:
        match = pattern.match(line.text)
        if not match:
            continue
        heading = nearest_heading(headings, line.position)
        if heading is None:
            continue
        events.append(CodeEvent(line.position, line.page, line.top, clean(match.group(1)), clean(match.group(2)), heading, skill_lines, component_lines, object_lines, unit_lines))
    return events


def extract_ef(path: Path) -> tuple[list[CurriculumRow], list[str]]:
    headings: list[Heading] = []
    skill_lines: list[TextLine] = []
    unit_lines: list[TextLine] = []
    object_lines: list[TextLine] = []
    warnings: list[str] = []
    with pdfplumber.open(path) as pdf:
        for page_number, page in enumerate(pdf.pages, start=1):
            if page_number < 115:
                continue
            words = page.extract_words(x_tolerance=1, y_tolerance=2, keep_blank_chars=False)
            page_full = full_lines(words, page_number, page.height)
            headings.extend(filter(None, (parse_ef_heading(line) for line in page_full)))
            skill_lines.extend(lines(words, 240, 357, page_number, page.height))
            unit_lines.extend(lines(words, 84, 158, page_number, page.height))
            object_lines.extend(lines(words, 158, 240, page_number, page.height))
    headings.sort(key=lambda item: item.position)
    events = code_events(headings, skill_lines, [], object_lines, unit_lines)
    heading_positions = {heading.position for heading in headings}
    rows: list[CurriculumRow] = []
    last_unit: dict[tuple[str, str], str] = {}
    last_object: dict[tuple[str, str], str] = {}
    for index, event in enumerate(events):
        next_code = events[index + 1].position if index + 1 < len(events) else float("inf")
        next_heading = next((heading.position for heading in headings if heading.position > event.position + 2 and (heading.section != event.heading.section or heading.year != event.heading.year)), float("inf"))
        end = min(next_code, next_heading)
        description_tail = content_between(skill_lines, event.position + 0.1, end, heading_positions)
        # Remove o código, que está na primeira linha, sem perder a descrição que o acompanha.
        first_line = next((line for line in skill_lines if abs(line.position - event.position) < 0.1), None)
        if first_line:
            match = re.match(r"^\(MS\s*\.\s*[^)]+\)\s*(.*)$", first_line.text, re.IGNORECASE)
            first = clean(match.group(1)) if match else event.first_text
            tail_lines = [line for line in skill_lines if event.position < line.position < end]
            description_tail = content_between(tail_lines, event.position, end, heading_positions)
            description = clean(" ".join(filter(None, [first, description_tail])))
        else:
            description = clean(" ".join(filter(None, [event.first_text, description_tail])))
        key = (event.heading.section, event.heading.year)
        unit = content_between(unit_lines, event.position - 2, end, heading_positions)
        obj = content_between(object_lines, event.position - 2, end, heading_positions)
        if unit:
            last_unit[key] = unit
        if obj:
            last_object[key] = obj
        component, sigla = EF_COMPONENTS[event.heading.section]
        years = ef_years(event.heading.year)
        etapa = "EF_AI" if max(int(year[2:]) for year in years) <= 5 else "EF_AF"
        rows.append(CurriculumRow(
            etapa=etapa,
            years=years,
            component=component,
            sigla=sigla,
            code=event.code,
            description=description,
            unit=last_unit.get(key, ""),
            object=last_object.get(key, ""),
            source_page=event.page,
            origin="CURRICULO_REFERENCIA_MS_EF_V1_10",
            source_document="CURRICULO_REFERENCIA_MS_EF_V1_10",
        ))
    if not rows:
        warnings.append("Nenhuma habilidade do Ensino Fundamental foi extraída.")
    return deduplicate(rows, warnings), warnings


def em_layout(section: str) -> tuple[tuple[float, float], tuple[float, float] | None, tuple[float, float], tuple[float, float] | None, str | None]:
    if section == "LINGUAGENS":
        return (79, 225), (225, 287), (287, 379), None, None
    if section == "LINGUA PORTUGUESA":
        return (79, 263), None, (263, 379), None, "Língua Portuguesa"
    if section == "MATEMATICA":
        return (112, 234), None, (234, 349), (52, 112), "Matemática"
    if section == "CIENCIAS HUMANAS":
        return (79, 171), (171, 232), (232, 352), None, None
    if section == "CIENCIAS DA NATUREZA":
        return (84, 191), (191, 264), (264, 378), None, None
    raise ValueError(section)


def component_occurrences(lines_: list[TextLine]) -> list[tuple[float, str]]:
    result: list[tuple[float, str]] = []
    for index, line in enumerate(lines_):
        candidates = [line.text]
        if index + 1 < len(lines_) and lines_[index + 1].position - line.position < 28:
            candidates.append(f"{line.text} {lines_[index + 1].text}")
        if index + 2 < len(lines_) and lines_[index + 2].position - line.position < 42:
            candidates.append(f"{line.text} {lines_[index + 1].text} {lines_[index + 2].text}")
        for candidate in reversed(candidates):
            key = folded(candidate)
            if key in EM_COMPONENTS:
                if not result or result[-1] != (line.position, key):
                    result.append((line.position, key))
                break
    return result


def extract_em(path: Path) -> tuple[list[CurriculumRow], list[str]]:
    headings: list[Heading] = []
    page_words: dict[int, tuple[list[dict], float]] = {}
    warnings: list[str] = []
    with pdfplumber.open(path) as pdf:
        for page_number, page in enumerate(pdf.pages, start=1):
            if not 155 <= page_number <= 340:
                continue
            words = page.extract_words(x_tolerance=1, y_tolerance=2, keep_blank_chars=False)
            page_words[page_number] = (words, page.height)
            headings.extend(filter(None, (parse_em_heading(line) for line in full_lines(words, page_number, page.height))))
    headings.sort(key=lambda item: item.position)
    skill_lines: list[TextLine] = []
    object_lines: list[TextLine] = []
    unit_lines: list[TextLine] = []
    component_lines: list[TextLine] = []
    for page_number, (words, height) in page_words.items():
        segments = [heading for heading in headings if heading.page == page_number]
        breakpoints = sorted([(55.0, nearest_heading(headings, (page_number - 1) * 1000 + 55))] + [(heading.position - (page_number - 1) * 1000, heading) for heading in segments], key=lambda item: item[0])
        for index, (top, heading) in enumerate(breakpoints):
            if heading is None:
                continue
            bottom = breakpoints[index + 1][0] if index + 1 < len(breakpoints) else height - 35
            skill_range, component_range, object_range, unit_range, _fixed = em_layout(heading.section)
            segment_words = [word for word in words if top - 2 <= word["top"] < bottom]
            skill_lines.extend(lines(segment_words, *skill_range, page_number, height))
            object_lines.extend(lines(segment_words, *object_range, page_number, height))
            if component_range:
                component_lines.extend(lines(segment_words, *component_range, page_number, height))
            if unit_range:
                unit_lines.extend(lines(segment_words, *unit_range, page_number, height))
    events = code_events(headings, skill_lines, component_lines, object_lines, unit_lines)
    heading_positions = {heading.position for heading in headings}
    occurrences = component_occurrences(component_lines)
    rows: list[CurriculumRow] = []
    for index, event in enumerate(events):
        next_code = events[index + 1].position if index + 1 < len(events) else float("inf")
        next_heading = next((heading.position for heading in headings if heading.position > event.position + 2 and (heading.section != event.heading.section or heading.year != event.heading.year)), float("inf"))
        end = min(next_code, next_heading)
        first_line = next((line for line in skill_lines if abs(line.position - event.position) < 0.1), None)
        first = event.first_text
        if first_line:
            match = re.match(r"^\(MS\s*\.\s*[^)]+\)\s*(.*)$", first_line.text, re.IGNORECASE)
            first = clean(match.group(1)) if match else first
        description = clean(" ".join(filter(None, [first, content_between([line for line in skill_lines if event.position < line.position < end], event.position, end, heading_positions)])))
        _skill_range, _component_range, _object_range, _unit_range, fixed = em_layout(event.heading.section)
        components: list[tuple[float, str]] = []
        if fixed:
            key = folded(fixed)
            components = [(event.position, key)]
        else:
            components = [(position, key) for position, key in occurrences if event.position - 3 <= position < end]
            if not components:
                prior = [(position, key) for position, key in occurrences if position < event.position and nearest_heading(headings, position) == event.heading]
                if prior:
                    components = [prior[-1]]
        if not components:
            warnings.append(f"p.{event.page}: {event.code} sem componente identificável; registro não importado.")
            continue
        unique_components: list[tuple[float, str]] = []
        for item in components:
            if item[1] not in [existing[1] for existing in unique_components]:
                unique_components.append(item)
        for component_index, (component_position, component_key) in enumerate(unique_components):
            object_end = unique_components[component_index + 1][0] if component_index + 1 < len(unique_components) else end
            obj = content_between(object_lines, max(event.position - 2, component_position - 2), object_end, heading_positions)
            unit = content_between(unit_lines, event.position - 2, end, heading_positions)
            component, sigla = EM_COMPONENTS[component_key]
            rows.append(CurriculumRow(
                etapa="EM",
                years=[event.heading.year],
                component=component,
                sigla=sigla,
                code=event.code,
                description=description,
                unit=unit,
                object=obj,
                source_page=event.page,
                origin="CURRICULO_REFERENCIA_MS_EM_V1_1",
                source_document="CURRICULO_REFERENCIA_MS_EM_V1_1",
            ))
    if not rows:
        warnings.append("Nenhuma habilidade do Ensino Médio foi extraída.")
    return deduplicate(rows, warnings), warnings


def deduplicate(rows: list[CurriculumRow], warnings: list[str]) -> list[CurriculumRow]:
    exact: dict[tuple[str, str, str, str], CurriculumRow] = {}
    descriptions: dict[tuple[str, str, str], set[str]] = defaultdict(set)
    for row in rows:
        code = row.code or ""
        descriptions[(row.origin, row.component, code)].add(row.description)
        key = (row.origin, row.component, code, row.description)
        if key in exact:
            exact[key].years = sorted(set(exact[key].years + row.years), key=lambda value: (value[:2], int(value[2:])))
            if not exact[key].unit and row.unit:
                exact[key].unit = row.unit
            if not exact[key].object and row.object:
                exact[key].object = row.object
            continue
        exact[key] = row
    for (origin, component, code), values in descriptions.items():
        if code and len(values) > 1:
            warnings.append(f"Código com descrições diferentes: {origin} / {component} / {code} ({len(values)} descrições).")
    return sorted(exact.values(), key=lambda row: (row.etapa, row.component, row.years, row.code or "", row.description))


def write_csv(path: Path, rows: list[CurriculumRow]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8-sig", newline="") as stream:
        writer = csv.DictWriter(stream, fieldnames=CSV_FIELDS)
        writer.writeheader()
        writer.writerows(row.as_csv() for row in rows)


def validate(rows: list[CurriculumRow]) -> list[str]:
    warnings = []
    for index, row in enumerate(rows, start=2):
        if not row.description:
            warnings.append(f"linha {index}: habilidade sem descrição")
        if not row.origin:
            warnings.append(f"linha {index}: habilidade sem origem")
        if not row.years:
            warnings.append(f"linha {index}: habilidade sem ano/série")
        if re.search(r"^(?:HABILIDADES?|AÇÕES DIDÁTICAS|SUGESTÕES DIDÁTICAS)\b|\b(?:HABILIDADES?|AÇÕES DIDÁTICAS|SUGESTÕES DIDÁTICAS)$", row.description, re.IGNORECASE):
            warnings.append(f"linha {index}: possível cabeçalho na descrição de {row.code}")
        if re.search(r"\s-\s[a-záàâãéêíóôõúç]{1,3}\b", row.description):
            warnings.append(f"linha {index}: possível palavra cortada em {row.code}")
    return warnings


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--ef", type=Path, required=True)
    parser.add_argument("--em", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    ef_rows, ef_warnings = extract_ef(args.ef)
    em_rows, em_warnings = extract_em(args.em)
    all_warnings = ef_warnings + em_warnings + validate(ef_rows) + validate(em_rows)
    write_csv(args.output / "habilidades_ef_ms.csv", ef_rows)
    write_csv(args.output / "habilidades_em_ms.csv", em_rows)
    print(f"Ensino Fundamental: {len(ef_rows)} habilidades importáveis")
    print(f"Ensino Médio: {len(em_rows)} habilidades importáveis")
    print(f"Advertências: {len(all_warnings)}")
    for warning in all_warnings[:100]:
        print(f"AVISO: {warning}")
    return 1 if not ef_rows or not em_rows else 0


if __name__ == "__main__":
    raise SystemExit(main())
