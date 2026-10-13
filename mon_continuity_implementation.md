# MON 1.8.8 — 구현 및 검증 범위

수집·시세·게시 실패 경계를 분리하고 지속 복구 상태 MON-CONTINUITY-1.0을 추가했다. 일반 MON 실행은 새 코드 배포 뒤에도 ChatGPT 최종 판단이 필요하다. 현황 저장으로 새 추천 완료를 대체하지 않는다.

새 경로는 캐시 scope/내용 SHA/시각 검증, 상세 게시 장애 후 독립 시세 수집, 모든 요청의 지속 복구 작업, 새 자료의 분석 인계, 보호 원자 checkpoint, 웹 페이지 조회, 원격 보고서 게시 journal이다. GET ?view=continuity는 읽기 전용이며 cursor/since로 전체 작업을 조회한다. bootstrap·status·웹 화면도 같은 현황을 표시한다.

실패·원문 부족·신규 미달은 보고서에서 계속 보존한다. 원래 자료·판단시각을 유지하며 실제 수행하지 않은 현재가 검증이나 최종 경쟁을 완료했다고 쓰지 않는다. mon_result.md의 추천은 기존 검증 파일 쌍 경로에서만 갱신한다. mon_runtime_result.json은 새 처리 현황 파일로 원격 GET 검증 후에만 saved가 된다.

테스트는 합성 자료·로컬 HTTP·가짜 원격 transport만 사용했다. native daemon tick에서 상세 PUT 시간초과 뒤에도 시세 수집과 pending 저장을 관측했다. 응답 유실 PUT의 검증 의도를 먼저 저장하고 다음 허용 시각의 GET 한 번으로 saved 확인하며 두 번째 PUT이 없음을 검증했다. 이전 기준시간이 지나도 보고서/작업을 삭제하지 않았다. 개별 결과와 최종 source SHA는 tests/mon_continuity_tests.json·tests/mon_continuity_http_tests.json 및 mon_continuity_acceptance.json에 기록한다.

HTTP 최초 시험8 PASS/1 FAIL은 시험 코드가 기존 status JSON의 data 경로 대신 최상위 continuity를 읽은 오류였다. 기존 계약을 유지하고 시험을 data.continuity로 정정했다. tests/mon_continuity_http_initial.json에 실패 기록을 보존했다. 시험 성공을 위해 제품의 권한·완료 검증을 완화하지 않았다.

실제 NAS1.8.8 배포·새 원천 복구·새 시장 선정·시장 결과/통계 변경·주문은 수행하지 않았다. Git 검수 브랜치 저장과 배포는 별개다. 장애 중 원격 저장은 연결 회복/권한 정정 전까지 대기하며 로컬 상태가 먼저 보존된다. 이 변경으로 모든 외부 장애가 항상 해결되거나 추천이 항상 발생한다고 보장하지 않는다.
