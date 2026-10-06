// Inputs explicitly edit UTC; untouched values retain original precision.
export const utcInput=value=>value?new Date(value).toISOString().slice(0,19):''
export const utcPayload=(value,original)=>value===utcInput(original)?original||null:value?new Date(value+'Z').toISOString():null
