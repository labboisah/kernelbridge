import { test } from 'node:test';
import assert from 'node:assert/strict';
import { commissionSplit } from '../../resources/js/Utils/commissionSplit.js';
test('calculates all five shares',()=>assert.deepEqual(commissionSplit('10000.00',['7.50','20.00','42.50','10.00','20.00']),[75000,200000,425000,100000,200000]));
test('distributes rounding without losing or creating money',()=>{
    assert.deepEqual(commissionSplit('0.01',[20,20,20,20,20]),[1,0,0,0,0]);
    for(const amount of ['0','0.03','100.01','999999999.99']) {
        const split=commissionSplit(amount,[7.5,30,30,16.25,16.25]);
        assert.equal(split.reduce((sum,value)=>sum+value,0),Math.round(Number(amount)*100));
    }
});
test('rejects invalid amounts and allocations',()=>{
    for(const amount of ['-1','1.234','','NaN','1000000000']) assert.equal(commissionSplit(amount,[20,20,20,20,20]),null);
    for(const rates of [[20,20,20,20],[50,50,50,0],[-1,51,25,25],[100,0,0]]) assert.equal(commissionSplit('100',rates),null);
});
