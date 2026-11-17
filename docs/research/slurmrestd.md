# slurmrestd paths, authentication, and visibility

Research for [#17](https://github.com/somethingwithproof/plugin_hpcsched/issues/17), run 2026-11-15. Question: do the endpoints, headers, version discovery, and conditional queries behave as documented, and what can a minimally privileged account see?

**Result.** Every endpoint, header, and default in the plan's REST table was found in source, with two corrections and one finding that changes the security design. Correction one: the 304 response to `update_time` on `/nodes` is safe to use only from 26.05.3. The initial 26.05 releases also fail when partitions are unchanged (see Conditional queries below). On 24.11, 25.05, and 25.11 the same query goes through the generic error path, so the plugin must not rely on it unless it has checked the release. Correction two: native TLS is 25.05 and later, and needs the s2n library at build time. The finding: "GET-only" is not a safe allowlist. `GET /slurm/{parser}/reconfigure/` asks `slurmctld` to reconfigure, and on 26.05 `GET /slurm/{parser}/job/{job_id}/requeue` requeues a job. The plugin needs an exact list of path and method pairs, not a method rule. Least-privilege visibility, token behavior for a user with no association, and failure modes are *live*.

## Method

Source at the tags in [slurm-releases.md](slurm-releases.md), under `src/slurmrestd/` (the server and the `openapi/slurmctld` and `openapi/slurmdbd` plugins). Documentation from `slurm.schedmd.com` fetched 2026-11-15: [REST API details](https://slurm.schedmd.com/rest.html), [REST clients](https://slurm.schedmd.com/rest_clients.html), [slurmrestd man page](https://slurm.schedmd.com/slurmrestd.html), [JWT](https://slurm.schedmd.com/jwt.html), [REST reference](https://slurm.schedmd.com/rest_api.html). Nothing was run.

## Endpoints

GET paths bound by `openapi/slurmctld/api.c` ([26.05](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmrestd/plugins/openapi/slurmctld/api.c)); `{parser}` is `v0.0.NN`. "Methods" lists every method bound to that path at that release, from `api.c`.

| Path | Methods at 26.05 | In 24.11 and 25.05 | Plan use | Status |
|---|---|---|---|---|
| `/slurm/{parser}/jobs/` | GET, DELETE | same | jobs | confirmed |
| `/slurm/{parser}/jobs/state/` | GET | same (present since 23.11) | light state counts | confirmed; returns only job id, array ids, het leader, and state ([slurm.h](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/slurm/slurm.h#L2113-L2122)) |
| `/slurm/{parser}/nodes/` | GET, POST | same | nodes | confirmed |
| `/slurm/{parser}/partitions/` | GET, POST | GET only | partitions | confirmed |
| `/slurm/{parser}/reservations/` | GET, POST | GET (24.11); GET, POST (25.05) | reservations | confirmed |
| `/slurm/{parser}/shares` | GET | same | shares | confirmed (no trailing slash) |
| `/slurm/{parser}/diag/` | GET | same | health | confirmed |
| `/slurm/{parser}/ping/` | GET | same | health | confirmed |
| `/slurmdb/{parser}/jobs/` | GET | same | accounting | confirmed ([slurmdbd api.c line 651](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmrestd/plugins/openapi/slurmdbd/api.c#L651)) |
| `/slurm/{parser}/reconfigure/` | **GET** | same | never | confirmed: the handler calls `slurm_reconfigure()` ([control.c line 55](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmrestd/plugins/openapi/slurmctld/control.c#L55), route at [api.c line 106](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmrestd/plugins/openapi/slurmctld/api.c#L106)) |
| `/slurm/{parser}/job/{job_id}/requeue` | **GET** | absent | never | confirmed in 26.05 only ([api.c line 309](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmrestd/plugins/openapi/slurmctld/api.c#L309)); the handler calls `slurm_requeue2()` |
| `/slurm/{parser}/job/{job_id}`, `/node/{node_name}`, `/partition/...`, `/reservation/...`, `/job/submit`, `/job/allocate` | GET, POST, DELETE variants | same | never | confirmed |
| `GET /openapi/v3`, `/openapi.json`, `/openapi.yaml` | n/a | n/a | version discovery | documented ([REST clients](https://slurm.schedmd.com/rest_clients.html)); response without a token is *live* |

Trailing slashes are part of the bound path in source (`jobs/`, `nodes/`, `ping/`, and no slash for `shares`). Whether the server accepts a path with the other form is *live*.

Final allowlist for the plugin (exact method and path, no pattern match): `GET /slurm/{parser}/jobs/`, `.../jobs/state/`, `.../nodes/`, `.../partitions/`, `.../reservations/`, `.../shares`, `.../diag/`, `.../ping/`, and `GET /slurmdb/{parser}/jobs/`, with `{parser}` fixed by configuration. Anything with `reconfigure`, `requeue`, a path parameter in braces, or any method other than GET is refused in code, and a unit test lists the 26.05 routes above as must-refuse. The plan wording "GET-only path allowlist" should read "exact-path allowlist, GET only, which excludes the GET routes that change state".

## Authentication, listening, and limits

| Fact | Evidence | Status |
|---|---|---|
| Headers `X-SLURM-USER-NAME` and `X-SLURM-USER-TOKEN` | [http_auth_jwt.c lines 38 and 41](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/plugins/auth/jwt/http_auth_jwt.c#L38-L41), and the OpenAPI security scheme in [openapi.c](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmrestd/openapi.c#L250) | confirmed |
| Default `--max-connections` is 124, also settable by `SLURMRESTD_MAX_CONNECTIONS`; `-t` sets threads | [slurmrestd.c line 125](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmrestd/slurmrestd.c#L125), [usage.txt](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmrestd/usage.txt) | confirmed |
| Listens on `host:port`, `https://host:port`, or `unix:///path` | [slurmrestd man page](https://slurm.schedmd.com/slurmrestd.html) | confirmed (docs) |
| With a TLS plugin loaded, plain listeners auto-detect TLS handshakes; `https://` listeners require TLS and close non-TLS connections without an error response | man page, TLS section | confirmed (docs) |
| TLS needs `restd_cert_file.pem` and `restd_cert_key_file.pem` (paths overridable through `TLSParameters`) | man page | confirmed (docs) |
| The REST details page still says only unencrypted HTTP is supported | [rest.html](https://slurm.schedmd.com/rest.html) as plan states | confirmed; the man page and source supersede it for 25.05 and later |
| `slurmrestd` must not run as root or `SlurmUser` | [JWT page](https://slurm.schedmd.com/jwt.html) | confirmed (docs) |
| A token for the JWT scheme from `scontrol token` lasts 1800 seconds unless `lifespan=` is given | [JWT page](https://slurm.schedmd.com/jwt.html) | confirmed (docs), not run |
| Each cluster needs its own `slurmrestd` | [REST quick start](https://slurm.schedmd.com/rest_quickstart.html) | confirmed (docs) |

## Conditional queries

| Fact | Evidence | Status |
|---|---|---|
| `update_time` is an accepted query parameter on jobs, partitions, nodes, reservations, and config | `add_parse(TIMESTAMP, update_time, ...)` at [v0.0.45 parsers.c lines 11273 to 11318](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/plugins/data_parser/v0.0.45/parsers.c#L11273-L11318) | confirmed |
| `/nodes` returns 304 with an empty body when nothing changed | 26.05 [nodes.c lines 116 to 133](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmrestd/plugins/openapi/slurmctld/nodes.c#L116-L133) and [operations.c lines 362 to 377](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmrestd/operations.c#L362-L377); the 26.11 candidate has the same code | confirmed from 26.05.3; 26.05.0 to 26.05.2 send an unchanged answer to `resp_error` and pass `update_time` to the partition read too, an HTTP 500 when only partitions are unchanged ([26.05.3 changelog](https://github.com/SchedMD/slurm/blob/slurm-26-05-3-1/CHANGELOG/slurm-26.05.md)) |
| On 24.11, 25.05, and 25.11 the nodes handler has no such branch: a failed `slurm_load_node` goes to `resp_error` with `errno` | [25.11 nodes.c lines 116 to 122](https://github.com/SchedMD/slurm/blob/5f7be9109e151bc59099786d7e3faccbca105869/src/slurmrestd/plugins/openapi/slurmctld/nodes.c#L116-L122) | **differs** from the plan; what status code a client then sees is *live* |
| `/jobs/` returns 200 with a warning ("No job changes since update_time") when nothing changed | [26.05 jobs.c lines 131 to 140](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmrestd/plugins/openapi/slurmctld/jobs.c#L131-L140); present at 24.11 to 26.05 | confirmed |
| `/partitions/` and `/reservations/` have no `SLURM_NO_CHANGE_IN_DATA` branch at any release checked | `git grep` over the four tags | confirmed |
| The timestamp is evaluated for the whole collection, so a change returns everything | [REST API details](https://slurm.schedmd.com/rest.html) | confirmed (docs) |
| On 26.05 the nodes handler loads partitions with `update_time=0` on purpose, so partition changes do not suppress a node refresh | nodes.c lines 148 to 154 | confirmed |

Recommendation for the plan: do not use `update_time` for the first release. One snapshot per cycle already bounds the call count (ADR 0003), the saving applies to one endpoint on one release family, and a mishandled 304 (or the unspecified pre-26.05 behavior) costs more than it saves. Revisit with measurement.

## Visibility under `PrivateData`

Source checks of which RPCs refuse a non-operator when the matching `PrivateData` flag is set:

| `PrivateData` value | Behavior in 26.05 source | Status |
|---|---|---|
| `partitions` | the partition-info RPC returns `ESLURM_ACCESS_DENIED` to a caller who is not an operator ([proc_req.c lines 1985 to 1991](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmctld/proc_req.c#L1985-L1991)) | confirmed |
| `nodes` | hostlist expansion is refused to non-operators (proc_req.c line 1763); node records are filtered by MCS label and by whether any of the node's partitions is visible to the user ([node_mgr.c lines 920 to 940](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmctld/node_mgr.c#L920-L940)) | confirmed for the code paths read; what an unprivileged user sees is *live* |
| `jobs` | a job is hidden from a user who is not its owner and not a coordinator of its account ([job_mgr.c lines 10323 to 10330](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmctld/job_mgr.c#L10323-L10330)) | confirmed |
| `reservations` | non-operators get only reservations their association can use ([reservation.c lines 5004 to 5015](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmctld/reservation.c#L5004-L5015)) | confirmed |
| `usage` | hides other users' `sshare` usage | confirmed (docs: [slurm.conf](https://slurm.schedmd.com/slurm.conf.html)) |
| `SlurmUser` and root | always see everything | confirmed (docs) |

"Operator" here is `validate_operator()`: `SlurmUser`, root, or an accounting admin level of Operator or above. The practical account recipe is therefore: a dedicated operating-system user that is not `SlurmUser` and not root, with Operator level in accounting if `PrivateData` is set for partitions, nodes, or reservations, and a note that Operator carries more than read access (it is documented as a privileged level for several write operations in the [scontrol man page](https://slurm.schedmd.com/scontrol.html)). Without Operator, the plugin sees only what `PrivateData` leaves public. Whether an Operator token can be prevented from calling the state-changing endpoints is not something slurmrestd offers by path in the source read; The plugin's allowlist limits only the plugin's own requests. Another client that holds the Operator token can still call `GET /slurm/v0.0.NN/reconfigure/` directly, so token-level restriction needs a separate control. The recipe needs a live pass before it is written as instructions.

## Limits

- A token for a user with no association: the documents do not state what such a token may do, and no source was traced through job submission. *live*.
- Standby controller and expired token responses were not traced in source.
- Everything about HTTP status codes seen by a client is from code reading; no request was sent.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #17 | On 24.11, 25.05, 25.11, and 26.05, call `GET /nodes/?update_time=<now>` and record the status | the pre-26.05 behavior behind `resp_error` |
| #17 | Request each allowlisted path with and without a trailing slash | exact path forms |
| #17 | `GET /openapi/v3` with no token and with a token | version discovery needs auth or not |
| #17 | With `PrivateData` set to each value, call the endpoints as an ordinary user, an Operator, and `SlurmUser` | the visibility table and the account recipe |
| #17 | Using a token for a user with no association, call `POST /job/submit` | confirms the token cannot submit; record the error |
| #17 | Expired token, stopped controller, standby controller: record status and body | the failure-mode rows in the health page |
| #17 | Confirm a refused-by-plugin `GET /reconfigure/` is never sent (test with a mock server that records requests) | the allowlist is enforced in code |
