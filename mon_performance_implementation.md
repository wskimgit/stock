# MON 처리시간 개선 — 1.8.7 / 지시문v4.15

2026-10-13 KST. [설계 P2.2](mon_performance_design.md)를 작성하고 동일 조건의1.8.6 전후 시뮬레이션을 실행해 두 경로를 개선했다. PHP7.4.33·96MiB에서 함수23/실제 로컬 HTTP9, **32 PASS / 0 FAIL**이다. 과거114 PASS를 반복/합산하지 않았다.

## 확인된 병목과 최종 변경

- 해외 KIS 일봉은 현재 kisStock의 분할 기준 보호에서 KR이 아닌 모든 행을 미확인으로 판정해 항상 거절한다. 새 fetch는 해당 무익한 호출만 생략하고 같은 DETAIL_KIS_SPLIT_BASIS_UNVERIFIED 진단을 남겨 Yahoo/검증 대체 원천으로 간다. 국내 KIS 우선·원주가 분할 보호·KIS 현재가·KRX 독립 증명은 유지한다.
- 단일 청크는 조각마다 전체 artifact를 다시 읽고 검증한다. mon_artifact_read의 ranges를 추가해 최대8×64KiB(512KiB)를 한 요청으로 반환한다. artifact와 같은 의존 파일은 묶음 내 한 번 검증하지만 조각/전체 파일 SHA·길이·권한·원문 손상 검사와 기존 단일 계약은 유지한다. 요청을 넘어서는 캐시로 보호 검증을 생략하지 않는다.
- 지시문은 독립 읽기/검색 묶음, SHA 고정 원문의 모델 밖 재사용, 실제 입력 변경 때만 재준비, 일반 실행에 코드 개선/기존 PASS 재시험을 넣지 않는 순서와 큰 조각 묶음 전달을 명시했다. NAS 단일 데몬·96MiB·작업별 timeout·시간/횟수 초과 후 계속 처리 원칙은 유지한다.

## 전후 측정

| 시험 |1.8.6|1.8.7|확인|
|---|---:|---:|---|
| 해외80개 합성 일봉, native fetch 원천 호출 |162|81|50% 감소. KIS81→0. 봉/지수/지표/계획 SHA 동일|
| 위 지연 모델 |241.5초|81초|KIS GET2초·Yahoo GET1초·토큰0.5초 가정. 66.5% 감소이며 실제 외부 서버 시간 아님|
| 실제 고정 artifact8조각,3회 중앙값 |0.377141초|0.055561초|85.3% 단축. byte/SHA 동일|
| 24조각의 전체 artifact 검증 |24회|3회|8조각당8→1회|
| 조각 왕복 지연 모델(요청당2초) |48초|6초|모델링이며 실제 NAS 왕복 아님|
| 원천 시험 메모리 |16MiB|16MiB|96MiB 이하|
| 실제 조각 시험 최대 메모리 |21.88MiB|21.88MiB|96MiB 이하|

실제 조각 시험은 기존 artifact171ca6542a9134b9fb5c303c11246a79348d84387a21e523b616ee2055d094e0의1,505,517바이트 mon_data.json을 읽기만 했다. 세 회차의 연결 결과 SHA는0b0cb6d47900f784a3b1e1b8740f03423a33290d58f0d855e23bb4114743b853로 동일하다. 해외80개는 합성 자료/transport이며 원천 결과 SHA3517340b2916eee193bf07666633afd1d49e3faccb65c5896d71b6bc568e9a1e로 같다. 외부 시장 요청/주문0이다.

저장된 시장 통계의 최근 완결 표본은3388.258712초다. 준비 각1.468~1.698초·확정2.136초에 비해 전체가 길다. 원천 조회/검색/연계/모델 대기를 모두 정밀 계측하지 않았으므로 나머지 전체를 특정 원인으로 확정하지 않는다. 위 시험의85.3%를 전체 MON56분에 적용해 완료시간을 예측하지 않는다. 첫 표시·최종 완료·서버 계산·개별 요청/재시도 대기는 계속 구분한다.

## 변경 경로 검증

[함수23건](tests/mon_performance_tests.json): US/JP의 검증된 대체 일봉, KR 우선과 수정 옵션 보호, Yahoo 실패 후 StockAnalysis, 모든 대체 실패 보류, 단일/8청크/혼합 파일/짧은 끝 조각, 빈/9개/잘못된 경로/음수/float/범위 초과/64KiB 초과/알 수 없는 필드, 의존 파일·artifact 손상 거절, 전후 동일 SHA를 검증했다. 실제 [HTTP9건](tests/mon_performance_http_tests.json)은 capability·단일 호환·512KiB 묶음·조각 SHA·운영자 권한·잘못된 크기/개수/비공개 경로 거절을 확인했다. [PHP7.4 문법](tests/mon_performance_syntax.json)도 통과했다.

첫 시험의1 FAIL은 손상 시험 fixture가 native artifact 봉인 방식 대신 다른 canonical digest를 써 MON_ARTIFACT_INVALID를 받은 경우였다. 실제 제품은 손상을 거절했다. fixture를 정확한 native SHA 방식으로 고쳐 내부 파일 변경도 MON_ARTIFACT_HASH_CHANGED로 거절함을 확인했다. 성공 플래그·완료 검증·원천 대조를 약화해 빠르게 만든 변경은 없다.

[원천 이전](tests/mon_performance_foreign_baseline.json)·[원천 개선](tests/mon_performance_foreign_current.json), [실제 조각 이전](tests/mon_performance_chunks_baseline.json)·[실제 조각 개선](tests/mon_performance_chunks_current.json)에 시간·메모리·호출·SHA를 기록했다. 재현은 PHP7.4+zlib로 tests/mon_performance_test.php, MON_TEST_PHP를 지정한 tests/mon_performance_http_test.py를 실행한다. 전후 probe는 고정 기준 코드와 기존 보호 artifact의 상태 폴더/ID를 인자로 받으며 원시 데이터/인증을 출력하지 않는다.

## 적용과 남은 범위

07:30 KST 실제 NAS GET은 HTTP200/9.243366초/버전1.8.5였다. 현재 기간 누락과 가격 충돌2건은 남아 있었다. 새 코드는1.8.7이며 NAS `/web/mon.php` 교체 후 웹 수동 중지→시작과 버전/heartbeat 확인이 필요하다. 이번 작업에서는 NAS 설치·자동 시작/재시작·시장 자료·결과·운영 통계를 변경하지 않았다. 실제 전체 소요시간과 현재 가격 충돌 해소는 교체 후 별도 확인한다.
