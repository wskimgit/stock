#!/usr/bin/env python3
"""Offline MON design model. Synthetic events; no quotes, network or orders.

A models omissions in draft D0.1, not a benchmark of production mon.php.
B is the refined contract. Fixed expected outcomes are independent test oracles.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import random
import time
from dataclasses import dataclass, replace
from datetime import datetime, timezone
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, ensure_ascii=False,
                                     separators=(",", ":")).encode()).hexdigest()


@dataclass(frozen=True)
class Snapshot:
    symbol_id: str = "KR|KOSPI|990001"
    country: str = "KR"
    currency: str = "KRW"
    target: str = "D20"
    stock_date: str = "D20"
    benchmark_date: str = "D20"
    stock_dates: tuple = tuple(f"D{i:02}" for i in range(21))
    benchmark_dates: tuple = tuple(f"D{i:02}" for i in range(21))
    benchmark_id: str = "KOSPI"
    adjustment: str = "split_adjusted_cash_unadjusted"
    benchmark_adjustment: str = "split_adjusted_cash_unadjusted"
    rs_positive: bool = True
    technical_qualified: bool = True
    data_conflict: bool = False
    quote_at: float = 0.0
    quote_price: float = 100.0
    quote_independent: bool = True
    quote_delay: float | None = 0.0
    current_session: bool = True
    risk_verified: bool = True
    trading_verified: bool = True
    cached_ready: bool = True
    source_checked_at: str = "original-check-time"
    previous_snapshot_intact: bool = True
    previous_rs_positive: bool = True
    previous_technical_qualified: bool = True
    entry_zone: tuple = (99.0, 101.0)
    confirmed_adverse: bool = False
    risk_pending: tuple = ()
    risk_same_day: bool = True
    trading_checked_at: float = 0.0


class Model:
    def __init__(self, revision):
        self.refined = revision in ("B0", "B")
        self.edges_refined = revision == "B"
        self.artifacts = {}
        self.jobs = {}

    def previous_status(self, s):
        valid = (s.previous_snapshot_intact and s.previous_rs_positive and
                 s.previous_technical_qualified)
        return "previous_reference" if valid or not self.edges_refined else "needs_data"

    def operation(self, now, duration, deadline, cancellable=True):
        if now >= deadline:
            return {"end": now, "accepted": False, "missed": now > deadline,
                    "status": "not_started"}
        end = now + duration
        if self.refined and end > deadline and cancellable:
            return {"end": deadline, "accepted": False, "missed": False,
                    "status": "timeout"}
        return {"end": end, "accepted": (end <= deadline if self.refined else True),
                "missed": end > deadline,
                "status": "overrun" if self.refined and end > deadline else "done"}

    def extended_deadline(self, now):
        return 300 if self.refined else now + 300

    def canonical_context(self, c):
        out = dict(c)
        out["countries"] = sorted(out.get("countries", ["KR", "US", "JP"]))
        if len(out["countries"]) != len(set(out["countries"])):
            raise ValueError("duplicate_country")
        if self.refined:
            out = {k: out[k] for k in ("source_blob_sha", "rendered_at", "countries")}
            out["important_ids"] = sorted(set(c.get("important_ids", [])))
            dt = datetime.fromisoformat(c["rendered_at"].replace("Z", "+00:00"))
            out["rendered_at"] = dt.astimezone(timezone.utc).isoformat(timespec="microseconds")
        return out

    def token(self, source_sha, c):
        return digest({"source": source_sha, "context": self.canonical_context(c),
                       "contract": "design-only"})

    def metric_key(self, x):
        keys = ["bars", "rules"]
        if self.refined:
            keys += ["benchmark", "target", "calendar_verified", "adjustment",
                     "symbol_map", "tick_rules", "live_observation"]
        return digest({k: x[k] for k in keys})

    def classify(self, s, now):
        valid_identity = (s.symbol_id.split("|")[0] == s.country and
                          s.currency == {"KR": "KRW", "JP": "JPY", "US": "USD"}[s.country])
        if not valid_identity:
            return "quarantine"
        if s.data_conflict:
            return self.previous_status(s)
        if not (s.rs_positive and s.technical_qualified):
            return "ineligible"
        if self.refined and (s.stock_dates != s.benchmark_dates or
                s.adjustment != s.benchmark_adjustment or
                s.benchmark_id not in {"KR": {"KOSPI", "KOSDAQ"}, "JP": {"TOPIX"},
                                      "US": {"SP500"}}[s.country]):
            return self.previous_status(s)
        if s.stock_date != s.target or (self.refined and s.benchmark_date != s.target):
            return self.previous_status(s)
        ready = s.cached_ready if not self.refined else (
            s.quote_independent and 0 <= now - s.quote_at <= 300 and
            s.quote_delay is not None and 0 <= s.quote_delay <= 300 and
            s.current_session and s.risk_verified and s.trading_verified and
            (not self.edges_refined or (s.entry_zone[0] <= s.quote_price <= s.entry_zone[1] and
            not s.confirmed_adverse and not s.risk_pending and s.risk_same_day and
            0 <= now - s.trading_checked_at <= 300)))
        if self.edges_refined and s.confirmed_adverse:
            return "entry_blocked"
        return "ready" if ready else "conditional"

    def price_comparison(self, raw, stored, same_record, declared_display):
        if not self.refined:
            return "same" if abs(Decimal(raw) - Decimal(stored)) <= Decimal("0.01") else "conflict"
        if Decimal(raw) == Decimal(stored):
            return "same"
        if same_record and declared_display:
            rendered = Decimal(raw).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
            if rendered == Decimal(stored):
                return "display_only"
        return "conflict"

    def should_commit(self, old, new):
        if not self.refined:
            return digest(old) != digest(new)
        keys = ["numerical_inputs", "selection", "decision_reasons", "evidence", "risk", "plans"]
        return digest({k: old[k] for k in keys}) != digest({k: new[k] for k in keys})

    def rebase(self, base, chosen, latest):
        if latest["analysis_version"] != base["analysis_version"]:
            return {"status": "conflict", "overwritten": False}
        result = dict(chosen)
        result["collection"] = dict(latest["collection"])
        changed_details = latest["collection"].get("detail_hash") != base["collection"].get("detail_hash")
        result["pending_analysis"] = self.refined and changed_details
        result["actionable"] = chosen["actionable"] and not result["pending_analysis"]
        result["analysis_as_of"] = chosen["analysis_as_of"]
        return result

    def publish_receipt(self, acknowledged, remote_commit_matches, phase):
        if phase == "blob_only":
            return {"saved": False, "commits": 0, "pair_visible": "old"}
        if acknowledged or (self.refined and remote_commit_matches):
            return {"saved": True, "commits": 1, "pair_visible": "new"}
        return {"saved": "unknown" if self.refined else False,
                "commits": 1 if self.refined else 2, "pair_visible": "unknown"}

    def display_count(self, states):
        middle = sum(a != b for a, b in zip(states, states[1:])) if self.refined else len(states) - 1
        return min(5, 2 + min(1, middle))

    def page_cards(self, cards, maximum=12288):
        pages, page = [], []
        for card in cards:
            candidate = {"token": "t" * 64, "cards": page + [card], "page": 0}
            body = json.dumps(candidate, ensure_ascii=False)
            size = len(body.encode()) if self.refined else len(body)
            if size > maximum and page:
                pages.append(page)
                page = []
            if self.edges_refined:
                single = json.dumps({"token": "t"*64, "cards": [card], "page": len(pages)}, ensure_ascii=False)
                if len(single.encode()) > maximum:
                    sha = digest(card)
                    self.artifacts[sha] = card
                    card = {"id": card["id"], "overflow": True, "artifact_sha": sha,
                            "card_ref": "verified-artifact:"+sha}
            page.append(card)
        if page:
            pages.append(page)
        return [json.dumps({"token": "t" * 64, "cards": p, "page": i}, ensure_ascii=False)
                for i, p in enumerate(pages)]

    def validate_response(self, request, response):
        fields = ["symbol_id", "request_id"]
        if self.refined:
            fields += ["request_hash", "period", "criteria", "currency", "provider_symbol"]
        return all(request[k] == response[k] for k in fields) and (
            not self.refined or digest(response["payload"]) == response["payload_sha"])

    def retry_allowed(self, now, attempt, retry_after, collect_end):
        return attempt < 2 and now >= retry_after and (
            now < collect_end if self.refined else True)

    def late_response(self, arrived_at, cutoff):
        return "next_run_only" if self.refined and arrived_at > cutoff else "current_decision"

    def health(self, error):
        return "access_unconfirmed" if self.refined else "daemon_stopped"

    def qualified_progress(self, usable_reported):
        return "needs_snapshot_validation" if self.refined else "eligible"

    def receipt_location(self):
        return "post_publish_receipt" if self.refined else "inside_same_commit"

    def enqueue_publication(self, manifest, durable_write_ok=True):
        if self.edges_refined and not durable_write_ok:
            return {"ack": False, "status": "queue_unavailable", "job_count": len(self.jobs)}
        identifier = digest(manifest)
        if self.edges_refined:
            self.jobs.setdefault(identifier, {"manifest": manifest, "state": "queued"})
        else:
            self.jobs[str(len(self.jobs))] = {"manifest": manifest, "state": "queued"}
        return {"ack": True, "status": "queued", "job_count": len(self.jobs)}

    def public_status(self, final_available, save_status):
        if self.edges_refined:
            return {"final_available": final_available, "save_status": save_status,
                    "whole_run_complete": save_status in ("saved", "failed", "expired")}
        return {"final_available": final_available, "save_status": "saved" if final_available else save_status,
                "whole_run_complete": final_available}

    def name_from_map(self, claimed, authoritative):
        return authoritative if self.edges_refined else claimed

    def preserve_original_check(self, s, fetched_at):
        return s.source_checked_at if self.edges_refined else fetched_at

    def checked_file(self, expected_sha, file_content):
        return digest(file_content) == expected_sha if self.edges_refined else True

    def exposure_limit(self, selections, references, holdings):
        counts = {}
        for sid in selections + references:
            if sid in holdings:
                continue
            country = sid.split("|")[0]
            counts[country] = counts.get(country, 0) + 1
        return max(counts.values(), default=0) <= 3


BASE_INPUTS = {"bars": "stock-v1", "rules": "MON-P3.1", "benchmark": "index-v1",
               "target": "D20", "calendar_verified": True, "adjustment": "split",
               "symbol_map": "KR|KOSPI|990001", "tick_rules": "verified-v1",
               "live_observation": None}
CONTEXT = {"source_blob_sha": "a" * 40, "rendered_at": "2026-10-11T02:00:00Z",
           "countries": ["KR", "US", "JP"], "important_ids": ["KR|KOSPI|990001"]}
BUSINESS = {"numerical_inputs": "n1", "selection": ["KR|KOSPI|990001"],
            "decision_reasons": ["synthetic reason"], "evidence": ["e1"],
            "risk": "needs_check", "plans": [[99, 101]], "rendered_at": 1}
REQUEST = {"symbol_id": "KR|KOSPI|990001", "request_id": "request-1", "request_hash": "r1",
           "period": "D20", "criteria": "MON-P3.1", "currency": "KRW", "provider_symbol": "990001"}
RESPONSE = {**REQUEST, "payload": {"synthetic": True},
            "payload_sha": digest({"synthetic": True})}
BASE = {"analysis_version": "a1", "collection": {"quote_version": 1, "detail_hash": "d1"},
        "analysis_as_of": "decision-original", "actionable": True}


def changed_key(model, key):
    altered = dict(BASE_INPUTS)
    altered[key] = str(altered[key]) + "-changed"
    return model.metric_key(BASE_INPUTS) != model.metric_key(altered)


def boundary_run(model, tasks, publish_duration=8, cancellable=True):
    now, accepted, skipped = 10.0, 0, 0
    for duration in tasks:
        r = model.operation(now, duration, 100, cancellable)
        now = r["end"]
        if r["accepted"]:
            accepted += 1
        else:
            skipped += 1
        if now >= 100:
            break
    decision = model.operation(now, 8, 110, cancellable)
    now = decision["end"]
    final_at = now
    publish = model.operation(now, publish_duration, 120, cancellable)
    return {"final_at": final_at, "end": publish["end"], "accepted": accepted,
            "skipped": skipped, "missed": final_at > 120 or publish["missed"],
            "has_final": True, "saved": publish["accepted"]}


CASES = []


def case(identifier, title, expect, action):
    CASES.append((identifier, title, expect, action))


case("S01", "정상 완료봉·독립 현재가·기업/거래 확인", "ready", lambda p: p.classify(Snapshot(), 100))
case("S02", "주말 완료봉 재사용은 현재 완료봉이지만 진입 조건부", "conditional",
     lambda p: p.classify(replace(Snapshot(), current_session=False), 100))
case("S03", "일부 종목 실패에도 검증된 다른 종목 유지", ["ready", "previous_reference"],
     lambda p: [p.classify(Snapshot(), 100), p.classify(replace(Snapshot(), data_conflict=True), 100)])
case("S04", "과거 완료봉을 현재 추천으로 승격 금지", "previous_reference",
     lambda p: p.classify(replace(Snapshot(), stock_date="D19"), 100))
case("S05", "NAS 접근 시간 초과를 정지로 단정 금지", "access_unconfirmed", lambda p: p.health("CONNECT_TIMEOUT"))
case("S06", "수집 98초 시작·8초 호출", (100, False),
     lambda p: (p.operation(98, 8, 100)["end"], p.operation(98, 8, 100)["accepted"]))
case("S07", "수집 종료 시각에 새 호출 시작 금지", "not_started", lambda p: p.operation(100, 5, 100)["status"])
case("S08", "느린 저장으로 최종 표시를 지연시키지 않음", (True, False, 120),
     lambda p: (lambda r: (r["final_at"] <= 110, r["saved"], r["end"]))(boundary_run(p, [30, 30, 30], 50)))
case("S09", "확장 마감은 원래 시작+300초", 300, lambda p: p.extended_deadline(90))
case("S10", "취소 불가 호출 초과를 숨기지 않음", (False, True, "overrun"),
     lambda p: (lambda r: (r["accepted"], r["missed"], r["status"]))(p.operation(98, 80, 100, False)))
case("S11", "국가 순서 변경 토큰 동등", True,
     lambda p: p.token("a"*40, CONTEXT) == p.token("a"*40, {**CONTEXT, "countries": ["JP", "KR", "US"]}))
case("S12", "UTC 동등시각·중복 중요 ID 토큰 동등", True,
     lambda p: p.token("a"*40, CONTEXT) == p.token("a"*40, {**CONTEXT, "rendered_at": "2026-10-11T11:00:00+09:00", "important_ids": CONTEXT["important_ids"]*2}))
case("S13", "실제 소스 변경은 토큰 무효", True,
     lambda p: p.token("a"*40, CONTEXT) != p.token("b"*40, CONTEXT))
for i, key in enumerate(["bars", "rules", "benchmark", "target", "calendar_verified", "adjustment", "symbol_map", "tick_rules", "live_observation"], 14):
    case(f"S{i:02}", f"수치 캐시 무효화: {key}", True, lambda p, key=key: changed_key(p, key))
case("S23", "원래 현재가 301초 경과 후 진입 보류", "conditional", lambda p: p.classify(Snapshot(), 301))
case("S24", "양쪽 원문 계보가 확인된 표시 반올림만 허용", "display_only",
     lambda p: p.price_comparison("139.39500427246094", "139.40", True, True))
case("S25", "같은 크기의 실제 원천 가격 변경은 충돌", "conflict",
     lambda p: p.price_comparison("139.39500427246094", "139.40", False, False))
case("S26", "종목과 지수 조정 기준 불일치", "previous_reference",
     lambda p: p.classify(replace(Snapshot(), benchmark_adjustment="total_return"), 100))
case("S27", "TOPIX 대신 다른 지수를 사용한 일본 후보", "previous_reference",
     lambda p: p.classify(replace(Snapshot(), symbol_id="JP|TSE|9991", country="JP", currency="JPY", benchmark_id="SP500"), 100))
case("S28", "21개 종목·지수 날짜 중 하나 불일치", "previous_reference",
     lambda p: p.classify(replace(Snapshot(), benchmark_dates=("OTHER",)+Snapshot().benchmark_dates[1:]), 100))
case("S29", "가격 성공·최신 지수 누락은 현재 강도로 합성 금지", "previous_reference",
     lambda p: p.classify(replace(Snapshot(), benchmark_date="D19"), 100))
case("S30", "국가·통화 매핑 충돌 격리", "quarantine",
     lambda p: p.classify(replace(Snapshot(), currency="USD"), 100))
case("S31", "다른 요청 ID의 응답 격리", False,
     lambda p: p.validate_response(REQUEST, {**RESPONSE, "request_id": "superseded"}))
case("S32", "같은 ID라도 요청 해시 변경 시 격리", False,
     lambda p: p.validate_response(REQUEST, {**RESPONSE, "request_hash": "replaced"}))
case("S33", "손상된 상세 페이로드 격리", False,
     lambda p: p.validate_response(REQUEST, {**RESPONSE, "payload": {"synthetic": False}}))
case("S34", "표시시각만 달라진 결과 재커밋 금지", False,
     lambda p: p.should_commit(BUSINESS, {**BUSINESS, "rendered_at": 2}))
case("S35", "수치는 같아도 새로운 기업 근거는 저장", True,
     lambda p: p.should_commit(BUSINESS, {**BUSINESS, "evidence": ["e1", "e2"]}))
case("S36", "같은 진행 토큰 반복은 중간 표시 생략", 2, lambda p: p.display_count(["same"]*5))
case("S37", "실제 상태 변화가 있을 때 초기·중간·최종", 3, lambda p: p.display_count(["old", "new", "new"]))
case("S38", "UTF-8 한국어 카드도 12KiB 페이지 제한 준수", True,
     lambda p: (lambda pages: max(len(s.encode()) for s in pages) <= 12288 and
                sum(len(json.loads(s)["cards"]) for s in pages) == 10)(p.page_cards([{"id": i, "reason": "가"*1500} for i in range(10)])))
case("S39", "시세만 추가된 collection 병합·판단시각 보존", (2, "decision-original", False),
     lambda p: (lambda r: (r["collection"]["quote_version"], r["analysis_as_of"], r["pending_analysis"]))(p.rebase(BASE, BASE, {**BASE, "collection": {"quote_version": 2, "detail_hash": "d1"}})))
case("S40", "늦은 새 상세자료 병합 시 새 현재 추천으로 오인 금지", (True, False),
     lambda p: (lambda r: (r["pending_analysis"], r["actionable"]))(p.rebase(BASE, BASE, {**BASE, "collection": {"quote_version": 2, "detail_hash": "d2"}})))
case("S41", "동시 다른 판단 변경 덮어쓰기 금지", {"status": "conflict", "overwritten": False},
     lambda p: p.rebase(BASE, BASE, {**BASE, "analysis_version": "a2"}))
case("S42", "Git 저장 응답 유실·실제 ref 성공은 중복 커밋 없이 확인", {"saved": True, "commits": 1, "pair_visible": "new"},
     lambda p: p.publish_receipt(False, True, "ref"))
case("S43", "Git 응답 유실·확인도 불가이면 저장 상태 미확인", {"saved": "unknown", "commits": 1, "pair_visible": "unknown"},
     lambda p: p.publish_receipt(False, False, "ref"))
case("S44", "한 blob만 생성하고 중단해도 공개 두 파일은 기존 쌍", {"saved": False, "commits": 0, "pair_visible": "old"},
     lambda p: p.publish_receipt(False, False, "blob_only"))
case("S45", "100초 이후 재시도 금지", False, lambda p: p.retry_allowed(101, 1, 95, 100))
case("S46", "실패 최대2회·30초 대기 준수", (False, False, True),
     lambda p: (p.retry_allowed(70, 2, 65, 100), p.retry_allowed(64, 1, 65, 100), p.retry_allowed(65, 1, 65, 100)))
case("S47", "마감 이후 응답은 다음 판단용으로만 보존", "next_run_only", lambda p: p.late_response(101, 100))
case("S48", "생산자의 usable 보고를 추천 자격으로 승격 금지", "needs_snapshot_validation", lambda p: p.qualified_progress(True))
case("S49", "최종 커밋 SHA·저장시간은 저장 후 영수증에 기록", "post_publish_receipt", lambda p: p.receipt_location())
case("S50", "긍정 기술신호만으로 음의 상대강도 후보 승격 금지", "ineligible", lambda p: p.classify(replace(Snapshot(), rs_positive=False), 100))

# Second review adds edge cases after the first report exposed save-incomplete runs.
case("S51", "충돌 이전자료도 검증되지 않았으면 참고 선정 금지", "needs_data",
     lambda p: p.classify(replace(Snapshot(), data_conflict=True, previous_snapshot_intact=False), 100))
case("S52", "충돌 이전자료의 음의 상대강도는 참고 자격 없음", "needs_data",
     lambda p: p.classify(replace(Snapshot(), data_conflict=True, previous_rs_positive=False), 100))
case("S53", "검증 현재가라도 진입 가격 구간 밖이면 조건부", "conditional",
     lambda p: p.classify(replace(Snapshot(), quote_price=110), 100))
case("S54", "기업 위험 확인 여부와 별개로 확인된 악재는 신규 진입 차단", "entry_blocked",
     lambda p: p.classify(replace(Snapshot(), confirmed_adverse=True), 100))
case("S55", "기업 위험 미확인 항목이 남으면 ready 금지", "conditional",
     lambda p: p.classify(replace(Snapshot(), risk_pending=("unverified_filing",)), 100))
case("S56", "이전 날짜 기업 검증을 오늘 확인으로 승격 금지", "conditional",
     lambda p: p.classify(replace(Snapshot(), risk_same_day=False), 100))
case("S57", "거래 가능 확인 301초 경과 후 ready 금지", "conditional",
     lambda p: p.classify(replace(Snapshot(), trading_checked_at=-201), 100))
case("S58", "단일 초대형 카드도 원문 보존·페이지 크기 제한", True,
     lambda p: (lambda pages: max(len(s.encode()) for s in pages) <= 12288 and
                (not p.refined or p.artifacts[json.loads(pages[0])["cards"][0]["artifact_sha"]]["reason"] == "가"*20000))
               (p.page_cards([{"id": "large-card", "reason": "가"*20000}])))
case("S59", "같은 최종 파일 쌍 저장 재요청은 같은 작업 재사용", 1,
     lambda p: (p.enqueue_publication({"data_sha": "d1", "result_sha": "r1"}),
                p.enqueue_publication({"data_sha": "d1", "result_sha": "r1"}))[1]["job_count"])
case("S60", "영속 대기열 저장 실패를 접수 성공으로 표시 금지", False,
     lambda p: p.enqueue_publication({"data_sha": "d1", "result_sha": "r1"}, False)["ack"])
case("S61", "최종 표시 완료·저장 대기는 전체 완료와 분리", False,
     lambda p: p.public_status(True, "queued")["whole_run_complete"])
case("S62", "응답 유실로 저장 여부를 모르면 saved라고 표시 금지", "unknown",
     lambda p: p.public_status(True, "unknown")["save_status"])
case("S63", "표시 종목명을 검증 코드 매핑으로 고정", "검증된 이름",
     lambda p: p.name_from_map("잘못 추정한 이름", "검증된 이름"))
case("S64", "캐시 재조회가 원래 완료봉 검증시각을 덮어쓰지 않음", "original-check-time",
     lambda p: p.preserve_original_check(Snapshot(), "new-fetch-time"))
case("S65", "파일 생성 후 교체된 본문을 다른 SHA로 저장 금지", False,
     lambda p: p.checked_file(digest({"file": "original"}), {"file": "replaced"}))
case("S66", "국가별 추천+참고3개 한도·보유분 별도", (False, True),
     lambda p: (p.exposure_limit(["KR|KOSPI|990001", "KR|KOSPI|990002", "KR|KOSPI|990003"], ["KR|KOSPI|990004"], []),
                p.exposure_limit(["KR|KOSPI|990001", "KR|KOSPI|990002", "KR|KOSPI|990003"], ["KR|KOSPI|990004"], ["KR|KOSPI|990004"])))


def run_cases(revision):
    model = Model(revision)
    out = []
    for identifier, title, expected, action in CASES:
        actual = action(model)
        passed = actual == expected
        out.append({"id": identifier, "scenario": title, "expected": expected,
                    "actual": actual, "passed": passed})
    return out


def stress(seed=20261011, count=2500):
    rng = random.Random(seed)
    model = Model("B")
    failures, max_end, saved, partial = [], 0, 0, 0
    for i in range(count):
        tasks = [rng.uniform(0.01, 40) for _ in range(rng.randint(0, 30))]
        result = boundary_run(model, tasks, rng.uniform(0.01, 70), True)
        max_end = max(max_end, result["end"])
        saved += result["saved"]
        partial += not result["saved"]
        conditions = [result["has_final"], result["final_at"] <= 110,
                      result["end"] <= 120, not result["missed"]]
        snapshot = replace(Snapshot(), quote_at=rng.uniform(-400, 110),
                           current_session=rng.choice([True, False]),
                           risk_verified=rng.choice([True, False]),
                           benchmark_date=rng.choice(["D19", "D20"]))
        status = model.classify(snapshot, 110)
        if status == "ready":
            conditions += [0 <= 110-snapshot.quote_at <= 300,
                           snapshot.current_session, snapshot.risk_verified,
                           snapshot.benchmark_date == snapshot.target]
        if not all(conditions):
            failures.append({"iteration": i, "result": result})
    return {"seed": seed, "iterations": count, "failures": failures,
            "max_simulated_total_seconds": max_end, "simulated_saved_runs": saved,
            "simulated_save_incomplete_runs": partial,
            "scope": "Cancellable operation model; virtual time, not NAS or ChatGPT performance."}


def publication_recovery(seed=20261012, count=1000):
    """Fault injection into durable manifests; no Git requests are made."""
    rng = random.Random(seed)
    model = Model("B")
    saved = expired = conflicted = unconfirmed = 0
    problems = []
    for i in range(count):
        pair = {"data_sha": digest({"synthetic_data": i}),
                "result_sha": digest({"synthetic_result": i}),
                "decision_as_of": "fixed-decision", "base_head": "fixed-base"}
        a = model.enqueue_publication(pair)
        job_id = digest(pair)
        persisted = json.dumps(model.jobs)
        restarted = Model("B")
        restarted.jobs = json.loads(persisted)
        b = restarted.enqueue_publication(pair)
        correct = a["ack"] and b["job_count"] == i+1 and job_id in restarted.jobs
        completion = 125 + rng.uniform(1, 220)
        fault = rng.choice(["ok", "ack_lost", "analysis_conflict", "status_unknown"])
        if completion > 300:
            expired += 1
            state = "expired"
        elif fault == "analysis_conflict":
            conflicted += 1
            state = "conflict"
        elif fault == "status_unknown":
            unconfirmed += 1
            state = "unknown"
        else:
            receipt = restarted.publish_receipt(fault == "ok", True, "ref")
            correct = correct and receipt["commits"] == 1 and receipt["saved"] is True
            saved += 1
            state = "saved"
        restarted.jobs[job_id]["state"] = state
        correct = correct and restarted.jobs[job_id]["manifest"] == pair
        if not correct:
            problems.append(i)
        model = restarted
    return {"seed": seed, "iterations": count, "failures": problems,
            "simulated_saved": saved, "simulated_expired": expired,
            "simulated_analysis_conflict": conflicted,
            "simulated_save_unknown": unconfirmed,
            "scope": "In-memory durable-state restart and publication protocol model; no remote writes."}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", default="tests/mon_reinforcement_simulation.json")
    args = parser.parse_args()
    started = time.perf_counter()
    draft, intermediate, refined = run_cases("A"), run_cases("B0"), run_cases("B")
    random_runs = stress()
    recovery_runs = publication_recovery()
    result = {"contract": "MON-DESIGN-SIMULATION-1.0", "data_kind": "synthetic_offline",
              "production_code_modified": False, "draft_model": "D0.1 implementation assumptions",
              "refined_model": "D1.0", "case_count": len(CASES),
              "draft_passed": sum(c["passed"] for c in draft),
              "intermediate_passed": sum(c["passed"] for c in intermediate),
              "refined_passed": sum(c["passed"] for c in refined),
              "draft_failures": [c for c in draft if not c["passed"]],
              "intermediate_failures": [c for c in intermediate if not c["passed"]],
              "refined_cases": refined, "randomized": random_runs,
              "publication_recovery": recovery_runs,
              "actual_simulator_runtime_seconds": time.perf_counter()-started,
              "limitations": ["Virtual operation durations do not establish real end-to-end latency.",
                              "Uncancellable call overrun is detected, not prevented.",
                              "No live quotes, broker requests, NAS restart or orders.",
                              "RS/MACD formulas are assumed verified inputs; not retested here.",
                              "Publication behavior is an in-memory model, not live Git concurrency."]}
    Path(args.output).write_text(json.dumps(result, ensure_ascii=False, indent=2)+"\n")
    print(json.dumps({k: result[k] for k in ("case_count", "draft_passed", "intermediate_passed", "refined_passed", "actual_simulator_runtime_seconds")}, ensure_ascii=False))
    print(json.dumps({"draft_failure_ids": [c["id"] for c in result["draft_failures"]],
                      "intermediate_failure_ids": [c["id"] for c in result["intermediate_failures"]],
                      "randomized": random_runs, "publication_recovery": recovery_runs}, ensure_ascii=False))
    return 0 if result["refined_passed"] == len(CASES) and not random_runs["failures"] and not recovery_runs["failures"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
