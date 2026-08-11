<?php

namespace App\Enums;

/**
 * Every step in the "deploy a VM" flow, grouped by what each one actually
 * depends on (this is what decides which steps run in Bus::batch() vs
 * Bus::chain() in DeploymentPipeline):
 *
 *   CreateVpc
 *     -> [CreateSubnet | CreateAclList -> CreateAclRule] in PARALLEL
 *        (both only need vpc_id)
 *     -> AttachAcl (join point: needs subnet_id AND acl_list_id/rule)
 *     -> [DeployVm | ListPublicIp] in PARALLEL
 *        (ListPublicIp doesn't depend on anything at all)
 *     -> EnableStaticNat (join point: needs vm_id AND public_ip_id)
 *
 * The string value is also what testers put in `simulate.step` when they
 * want to force one specific step to fail or time out.
 */
enum FlowStep: string
{
    case CreateVpc = 'create_vpc';
    case CreateSubnet = 'create_subnet';
    case CreateAclList = 'create_acl_list';
    case CreateAclRule = 'create_acl_rule';
    case AttachAcl = 'attach_acl';
    case DeployVm = 'deploy_vm';
    case ListPublicIp = 'list_public_ip';
    case EnableStaticNat = 'enable_static_nat';
}
