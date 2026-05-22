# VulnBank MSA Adapter

이 디렉터리는 기존 VulnBank monolith를 MSA 형태로 전환하기 위한 작업 공간이다.

중요한 구분:

- `examples/vulnbank`: GitHub upstream VulnBank 원본에 가까운 monolith snapshot
- `examples/vulnbank-msa`: VulnBank 도메인을 여러 서비스로 분리하기 시작한 MSA adapter

현재 상태는 "원본 VulnBank 전체 기능을 1:1로 MSA 이식 완료"가 아니다. 다만 새로 만든 가짜 은행 앱이 아니라, 원본 VulnBank의 PHP `api.php`, `inc/common.php`, `inc/db.php` 구조를 기준으로 공통 PHP library와 서비스별 API entrypoint를 분리하기 시작한 상태다.

## Service Split

현재 MSA adapter는 다음 서비스로 나뉜다.

| Service | 역할 | 원본 대응 |
| --- | --- | --- |
| `user-service` | 로그인, 사용자 생성/수정/삭제, 비밀번호, 사용자 확인 | `api.php type=user`, `type=code`, `user*`, `codeGenerate`, `graphGetData` |
| `transaction-service` | 송금, 검증, 취소, 실패 거래 정리 | `api.php type=transaction`, `transaction*` |
| `status-service` | 서비스 상태 점검 | `api.php type=status`, `serviceState` |
| `file-service` | avatar upload | `api.php type=file`, `fileUpload` |
| `settings-service` | 설정 변경, DB reset | `api.php type=settings`, `settingsUpdate`, `dbReset` |
| `frontend` | 원본 `sources/www` UI snapshot | 기존 VulnBank 화면 기준점 |

## Why This Exists

원본 VulnBank는 PHP, MySQL, nginx가 한 컨테이너에 묶인 구조다. 그 상태로도 Golden Path에 태울 수 있지만, 멘토 피드백의 "MSA 형태"를 검증하려면 서비스별 이미지, 서비스별 스캔 결과, 서비스별 배포 상태가 필요하다.

따라서 이 adapter는 다음을 검증하기 위해 존재한다.

1. 한 워크로드가 여러 이미지로 구성될 때 Jenkins가 서비스를 반복 build하는지
2. Trivy 결과가 서비스별로 분리되어 남는지
3. 하나의 서비스에서 Critical이 나오면 전체 MSA gate가 BLOCK되는지
4. Helm/GitOps/ArgoCD가 여러 Deployment와 Service를 같은 release/app으로 관리하는지
5. 향후 VulnBank 원본 기능을 서비스 단위로 점진 이식할 수 있는지

## Current API

로컬에서 각 서비스를 직접 실행하면 다음 API를 사용할 수 있다.

```text
user-service
- POST /api.php type=user action=login
- POST /api.php type=user action=create
- POST /api.php type=user action=update
- POST /api.php type=user action=check
- POST /api.php type=code action=sms

transaction-service
- POST /api.php type=transaction action=send
- POST /api.php type=transaction action=verify
- POST /api.php type=transaction action=clear
- POST /api.php type=transaction action=cancel

status-service
- POST /api.php type=status action=get

file-service
- POST /api.php multipart upload_avatar

settings-service
- POST /api.php type=settings action=update
- POST /api.php type=settings action=resetdb
```

## Known Intentional Weaknesses

이 adapter는 보안 실습을 위해 일부 취약한 동작을 의도적으로 남긴다.

- `userCheck`는 원본처럼 동적 SQL 조립 흐름을 유지한다.
- `transactionSend`는 원본 business logic/race condition 실습 흐름을 유지한다.
- `serviceState`는 원본 status check/SSRF 계열 실습 지점을 유지한다.
- `fileUpload`는 원본 upload/ImageTragick 계열 실습 지점을 유지한다.

이 취약점은 운영용 코드가 아니라 Golden Path의 탐지, 증적, 정책 판단 시나리오를 만들기 위한 실습 포인트다.

## Next Migration Work

원본 VulnBank 기능을 더 많이 반영하려면 다음 순서로 진행한다.

1. 원본 UI의 `api.php` 호출을 frontend gateway에서 각 service로 라우팅한다.
2. PHP session 공유 문제를 Redis 또는 gateway token 방식으로 정리한다.
3. DB를 shared DB로 유지할지 service별 schema로 나눌지 결정한다.
4. 기존 취약점 시나리오가 MSA 구조에서도 재현되는지 DAST test로 고정한다.
5. Helm values와 GitOps profile을 서비스별 image tag로 관리한다.
