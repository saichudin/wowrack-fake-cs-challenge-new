# WowDev Contest - 7 August 2026

## Requirements

- **Language:** Any programming language is acceptable.
- **Dependencies:** NO third-party libraries.
- **Scheduling:** NO Cron jobs.
- **Code Quality:** Neat, readable, easy to understand, and highly efficient solution.
- **Concurrency:** Parallel processing is a **must** for jobs/subtasks that can be run in parallel.
- **Retry & Rollback Strategy:**
  - **Rollback on Failure:** If any subtask fails (`jobstatus = 2`), execute `rollback_action` to clean up all previously created resources and prevent orphaned assets.
  - **Retry on Timeout:** If a subtask times out, trigger `rollback_action` to clean up, then retry the entire 1 task flow from the beginning.

---

## Use Cases

1. **New User Deploy VM with Public IP** (`public_ip = true`)
2. **New User Deploy VM without Public IP** (`public_ip = false`)

3. **Error & Timeout Demonstrations:**
   - **Failed Job:** Handle subtask failure (`jobstatus = 2`).
   - **Timeout:** Handle subtasks that fail to process due to timeout.
4. setiap request baru dianggap user baru juga jadi tidak perlu validasi 1 user reqeust berulang kali
5. jika kita simulasikan request timeout (tidak dapat response sama sekali dari server), tidak perlu memikirkan apakah resource di server terbuat atau tidak, anggap di server fake-cs tidak memproses apa-apa karena timeout 
---

## Execution Steps

### 1. Prepare Network
- Create VPC
- Create Subnet
- Create ACL List
- Create ACL Rules
- Attach ACL List to Subnet

### 2. Deploy VM
- Deploy Virtual Machine using the created Subnet

### 3. Public IP Allocation *(Optional: `public_ip = true`)*
- Retrieve an available Public IP address
- Enable Static NAT mapping the VM to the Public IP

---

## API Specification

- **Base URL:** `https://fake-cs.virmata.com/api/api`
- **Async Pattern:** Endpoints marked as `Async = YES` return a `jobid`. You must poll `queryAsyncJobResult` until the job is completed to retrieve the final resource ID.

### Command Matrix

| Description | Command | Dependency | Async |
| --- | --- | --- | --- |
| Check Job | `queryAsyncJobResult(jobid)` | - | NO |
| Create VPC | `createVpc()` | - | YES |
| Create Subnet | `createNetwork(vpc_id)` | VPC | NO |
| Create ACL List | `createNetworkACLList(vpc_id)` | VPC | YES |
| Add ACL Rule | `createNetworkACL(acl_list_id)` | ACL list | YES |
| Attach ACL List to Subnet | `replaceNetworkACLList(acl_list_id, subnet_id)` | ACL list, Subnet | YES |
| Deploy VM | `deployVirtualMachine(subnet_id)` | Subnet | YES |
| List Public IP | `listPublicIpAddress()` | - | NO |
| Static NAT | `enableStaticNat(vm_id, public_ip_id)` | VM, Public IP | NO |
| Delete VPC | `deleteVPC(vpc_id)` | VPC | YES |
| Delete Subnet | `deleteNetwork(subnet_id)` | Subnet | YES |

> **Note:** Every asynchronous endpoint returns a `jobid`. The result of `queryAsyncJobResult` contains the actual resource output (e.g., `createVpc` returns `vpc_id`, which is required for `createNetwork` based on the dependency matrix). 
async = NO means the result is instant no need to check to job result
urutan delete: 1. subnet 2. vpc (acl dll sudah otomatis terhapus)
saat ambil public ip pastikan yang state = "Free"

#### Extra Parameters
result:
- jobstatus = 0 = processing
- jobstatus = 1 = success
- jobstatus = 2 = error

delay = processing job in seconds.

timeout = timeout in seconds. In PHP curl will timeout after 30 seconds.
#### Example Request
```bash
curl --location '[https://fake-cs.virmata.com/api/api?command=queryAsyncJobResult&jobid=1111-xxss-ss](https://fake-cs.virmata.app/api/api?command=queryAsyncJobResult&jobid=1111-xxss-ss)'
```

#### Note
- simulasikan bagaimana cara menghandle paralel jon, series job, dan jika da job yang bermasalah harus di rapihin (di clean up)

#### Goal
bagaimana caranya menghandle proses dari service yang kita buat ini saat hit ke fake-cs dengan berbagai kemungkinan (seperti failed atau timeout) se-efisien mungkin, solusi apa yang bisa kita berikan