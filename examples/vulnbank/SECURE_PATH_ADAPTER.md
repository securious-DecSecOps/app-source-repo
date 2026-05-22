# Secure Path Adapter Notes

이 디렉터리는 upstream VulnBank monolith 코드를 Golden Path에 태우기 위한 원본 기준점이다.

## Current Role

- 원본 VulnBank와 가까운 PHP/MySQL/nginx 단일 컨테이너 구조를 보관한다.
- AWS VM/k3s에서 "기존 취약 워크로드를 그대로 Golden Path에 온보딩"하는 기준으로 사용한다.
- MSA 전환 결과물은 이 디렉터리를 직접 덮어쓰기보다 `examples/vulnbank-msa`에서 별도로 진행한다.

## Why MSA Is Separate

원본 VulnBank는 하나의 컨테이너 안에 웹 서버, PHP runtime, DB, 시작 스크립트가 같이 들어간다. 이것을 MSA로 바꾸려면 단순 YAML 추가가 아니라 기능 분리, API 계약, 데이터 분리, 서비스별 Dockerfile, Helm values, GitOps image tag 관리가 필요하다.

따라서 repo에서는 다음 두 기준을 동시에 둔다.

| Path | 의미 |
| --- | --- |
| `examples/vulnbank` | 원본 VulnBank monolith 기준점 |
| `examples/vulnbank-msa` | VulnBank 도메인을 MSA로 분해하는 adapter 작업 공간 |

