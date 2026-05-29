# Workload Source — DevSecOps Golden Path

[SecuBank DevSecOps Golden Path](https://securious-decsecops.github.io/secubank-docs/)의 검증용 워크로드 소스다. 파이프라인이 이 코드를 빌드·스캔·배포하며 탐지 효능을 실증한다.

대표 워크로드 **VulnBank MSA**는 기존 VulnBank PHP 모놀리스를 6개 서비스로 1차 분해한 Shared-DB MSA 전환 PoC다.

## 구조

```
examples/vulnbank-msa/services/   # 6 MSA 서비스(user·transaction·status·file·settings·frontend) + Dockerfile + OpenAPI
examples/vulnbank/                # upstream 모놀리스 스냅샷(reference)
examples/simple-web/              # 단순 reference 워크로드
```

## ⚠ 의도된 취약점 — 운영 배포 금지

이 코드는 **보안 검증용 lab target**으로, 의도된 취약점이 살아있다:

| ID | 취약점 | 위치 |
| --- | --- | --- |
| V1 | 음수 송금(금액 부호 검증 누락) | `transaction-service` |
| V2 | IDOR — 거래내역 | `transaction-service` |
| V3 | IDOR — 회원정보 수정 | `settings-service` |
| V4 | 파일 업로드 → 웹쉘 RCE | `file-service` |

이 취약점들은 파이프라인의 **DAST/런타임 계층이 탐지·차단하는 대상**이다 (SAST는 구조적으로 못 잡음 — 탐지 효능 분석 참고). 상세 위치는 `devsecops-path/docs/intentional-vulnerabilities.md`.

## License

Apache License 2.0 — `LICENSE` 참고.
