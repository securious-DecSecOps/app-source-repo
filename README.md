# app-source-repo

VulnBank 앱 소스. 의도된 취약점 lab workload.

## 구조

- `examples/vulnbank/` — upstream VulnBank monolithic snapshot (reference)
- `examples/vulnbank-msa/` — Shared-DB MSA 6 서비스 + shared PHP 모듈 + OpenAPI 스펙
- `examples/simple-web/` — 단순 reference 워크로드
- `examples/online-boutique/`, `examples/wrongsecrets/` — placeholder

## ⚠ 운영 환경 배포 금지

이 코드는 보안 실습용입니다. 의도된 취약점(음수 송금, IDOR x2, 파일 업로드 RCE)이 있습니다.
자세한 vulnerability 위치는 devsecops-path repo의 `docs/intentional-vulnerabilities.md` 참고.
