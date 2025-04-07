/**
 * @param {Number} value 
 * @param {Array} numerals
 * @returns 
 */
export function num(value, numerals) {
	var t0 = value % 10,
		t1 = value % 100,
		vo = [];
  vo.push(value);
	if (value === 1 && numerals[1])
		vo.push(numerals[1]);
	else if ((value == 0 || (t0 >= 0 && t0 <= 1) || (t0 >= 5 && t0 <= 9) || (t1 > 10 && t1 < 20)) && numerals[0])
		vo.push(numerals[0]);
	else if (((t1 < 10 || t1 > 20) && t0 >= 2 && t0 <= 4) && numerals[2])
		vo.push(numerals[2]);
	return vo.join(' ');
}
