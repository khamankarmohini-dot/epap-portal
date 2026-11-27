<?php
/**
 * EPAP - Practice Problems Seeder (Content Only)
 * Run via: php setup.php --with-practice
 */
declare(strict_types=1);

function runSeedPractice(PDO $pdo): int
{
    $problems = [
        [1, 'dsa', 'Two Sum', 'Easy', 'Arrays & Hashing',
         'Given an array of integers <code>nums</code> and an integer <code>target</code>, return indices of the two numbers such that they add up to target.',
         ['2 <= nums.length <= 10^4', '-10^9 <= nums[i] <= 10^9', 'Only one valid answer exists.'],
         [['input' => 'nums = [2,7,11,15], target = 9', 'output' => '[0,1]', 'explanation' => 'nums[0] + nums[1] == 9']],
         ['python' => "def twoSum(nums, target):\n    seen = {}\n    for i, n in enumerate(nums):\n        if target - n in seen:\n            return [seen[target - n], i]\n        seen[n] = i\n",
          'java' => "class Solution {\n    public int[] twoSum(int[] nums, int target) {\n        return new int[]{};\n    }\n}",
          'cpp' => "#include <vector>\nusing namespace std;\nclass Solution {\npublic:\n    vector<int> twoSum(vector<int>& nums, int target) {\n        return {};\n    }\n};",
          'c' => "int* twoSum(int* nums, int numsSize, int target, int* returnSize) {\n    *returnSize = 2;\n    return NULL;\n}"],
         [['2 7 11 15\n9', '0 1', 1], ['3 2 4\n6', '1 2', 0]]
        ],
        [2, 'dsa', 'Reverse Linked List', 'Easy', 'Linked List',
         'Given the <code>head</code> of a singly linked list, reverse the list, and return the reversed list.',
         ['0 <= nodes <= 5000', '-5000 <= Node.val <= 5000'],
         [['input' => '1 2 3 4 5', 'output' => '5 4 3 2 1', 'explanation' => 'List is reversed.']],
         ['python' => "def reverseList(arr):\n    return arr[::-1]\n", 'java' => "// Reverse linked list", 'cpp' => "// Reverse linked list", 'c' => "// Reverse linked list"],
         [['1 2 3 4 5', '5 4 3 2 1', 1], ['10 20', '20 10', 0]]
        ],
        [3, 'dsa', 'Valid Parentheses', 'Easy', 'Stack & Queue',
         'Given a string <code>s</code> containing just the characters <code>( ) { } [ ]</code>, determine if the input string is valid.',
         ['1 <= s.length <= 10^4'],
         [['input' => '()[]{}', 'output' => 'true', 'explanation' => 'All brackets match in order.']],
         ['python' => "def isValid(s: str) -> bool:\n    pass\n", 'java' => "class Solution { public boolean isValid(String s) { return false; } }", 'cpp' => "class Solution { public: bool isValid(string s) { return false; } };", 'c' => "int isValid(char* s) { return 0; }"],
         [['()[]{}', 'true', 1], ['([)]', 'false', 0]]
        ],
        [4, 'dsa', 'Binary Search', 'Easy', 'Searching',
         'Given a sorted array of integers <code>nums</code> and a <code>target</code>, return its index or -1 in O(log n) time.',
         ['1 <= nums.length <= 10^4', 'All elements are unique and sorted ascending.'],
         [['input' => 'nums = [-1,0,3,5,9,12], target = 9', 'output' => '4', 'explanation' => '9 is at index 4.']],
         ['python' => "def search(nums, target):\n    return -1\n", 'java' => "class Solution { public int search(int[] nums, int target) { return -1; } }", 'cpp' => "class Solution { public: int search(vector<int>& nums, int target) { return -1; } };", 'c' => "int search(int* nums, int n, int target) { return -1; }"],
         [['-1 0 3 5 9 12\n9', '4', 1], ['-1 0 3 5 9 12\n2', '-1', 0]]
        ],
        [5, 'dsa', 'Maximum Subarray (Kadane)', 'Medium', 'Dynamic Programming',
         'Find the contiguous subarray with the largest sum and return its sum.',
         ['1 <= nums.length <= 10^5'],
         [['input' => '-2 1 -3 4 -1 2 1 -5 4', 'output' => '6', 'explanation' => 'Subarray [4,-1,2,1] has sum 6.']],
         ['python' => "def maxSubArray(nums):\n    pass\n", 'java' => "class Solution { public int maxSubArray(int[] nums) { return 0; } }", 'cpp' => "class Solution { public: int maxSubArray(vector<int>& nums) { return 0; } };", 'c' => "int maxSubArray(int* nums, int n) { return 0; }"],
         [['-2 1 -3 4 -1 2 1 -5 4', '6', 1], ['1', '1', 0]]
        ],
        [6, 'dsa', 'Merge Intervals', 'Medium', 'Sorting & Intervals',
         'Given an array of intervals, merge all overlapping intervals and return the non-overlapping intervals.',
         ['1 <= intervals.length <= 10^4'],
         [['input' => '[[1,3],[2,6],[8,10],[15,18]]', 'output' => '[[1,6],[8,10],[15,18]]', 'explanation' => '[1,3] and [2,6] overlap.']],
         ['python' => "def merge(intervals):\n    return []\n", 'java' => "// Merge intervals", 'cpp' => "// Merge intervals", 'c' => "// Merge intervals"],
         [['1,3 2,6 8,10 15,18', '1,6 8,10 15,18', 1]]
        ],
        [7, 'dsa', 'Binary Tree Level Order Traversal', 'Medium', 'Trees & BFS',
         'Given the root of a binary tree, return the level order traversal of its nodes values.',
         ['0 <= nodes <= 2000'],
         [['input' => 'root = [3,9,20,null,null,15,7]', 'output' => '[[3],[9,20],[15,7]]', 'explanation' => 'Level by level BFS.']],
         ['python' => "def levelOrder(root):\n    return []\n", 'java' => "// Level order", 'cpp' => "// Level order", 'c' => "// Level order"],
         [['3 9 20 null null 15 7', '3 | 9 20 | 15 7', 1]]
        ],
        [8, 'dsa', 'Number of Islands', 'Medium', 'Graphs & DFS/BFS',
         'Given an m x n 2D grid of 1s (land) and 0s (water), return the number of islands.',
         ['1 <= m, n <= 300'],
         [['input' => '4x5 grid', 'output' => '1', 'explanation' => 'All 1s are connected.']],
         ['python' => "def numIslands(grid):\n    return 0\n", 'java' => "// Number of islands", 'cpp' => "// Number of islands", 'c' => "// Number of islands"],
         [['11110\n11010\n11000\n00000', '1', 1]]
        ],
        [9, 'dsa', 'Coin Change', 'Medium', 'Dynamic Programming',
         'Return the fewest number of coins needed to make up a given amount, or -1 if impossible.',
         ['1 <= coins.length <= 12', '0 <= amount <= 10^4'],
         [['input' => 'coins = [1,2,5], amount = 11', 'output' => '3', 'explanation' => '11 = 5 + 5 + 1']],
         ['python' => "def coinChange(coins, amount):\n    return -1\n", 'java' => "// Coin change", 'cpp' => "// Coin change", 'c' => "// Coin change"],
         [['1 2 5\n11', '3', 1], ['2\n3', '-1', 0]]
        ],
        [10, 'dsa', 'LRU Cache Design', 'Hard', 'Design & Hash Table',
         'Design an LRU Cache supporting O(1) get and put operations.',
         ['1 <= capacity <= 3000'],
         [['input' => 'capacity=2, put(1,1), put(2,2), get(1)', 'output' => '1', 'explanation' => 'Key 1 is cached.']],
         ['python' => "class LRUCache:\n    def __init__(self, cap): pass\n", 'java' => "class LRUCache {}", 'cpp' => "class LRUCache {};", 'c' => "// LRU Cache"],
         [['2\nput 1 1\nput 2 2\nget 1', '1', 1]]
        ],
        [11, 'oop', 'Virtual Functions & Runtime Polymorphism', 'Easy', 'Polymorphism',
         'Create an abstract base class <code>Shape</code> with a pure virtual method <code>area()</code>. Derive <code>Circle</code> and <code>Rectangle</code>.',
         ['Dimensions are positive floats.'],
         [['input' => 'Circle(5.0)', 'output' => '78.54', 'explanation' => '3.14159 * 25']],
         ['python' => "from abc import ABC, abstractmethod\nclass Shape(ABC):\n    @abstractmethod\n    def area(self): pass\n", 'java' => "abstract class Shape { abstract double area(); }", 'cpp' => "class Shape { public: virtual double area() = 0; };", 'c' => "// Function pointers"],
         [['circle 5', '78.54', 1], ['rect 4 5', '20.00', 0]]
        ],
        [12, 'oop', 'Operator Overloading (Complex Numbers)', 'Medium', 'Operator Overloading',
         'Overload + and * operators for a <code>Complex</code> number class.',
         ['Real and imaginary parts are integers.'],
         [['input' => '(3+2i) + (1+7i)', 'output' => '4+9i', 'explanation' => 'Add real and imag parts.']],
         ['python' => "class Complex:\n    def __add__(self, other): pass\n", 'java' => "// Complex add method", 'cpp' => "class Complex {};", 'c' => "// Struct Complex"],
         [['3 2 1 7', '4+9i', 1]]
        ],
        [13, 'oop', 'Generic Stack Template', 'Medium', 'Templates & Generics',
         'Implement a generic Stack supporting push, pop, peek, and isEmpty.',
         ['1 <= operations <= 1000'],
         [['input' => 'push 10, push 20, peek', 'output' => '20', 'explanation' => 'LIFO order.']],
         ['python' => "class Stack:\n    pass\n", 'java' => "class Stack<T> {}", 'cpp' => "template<typename T> class Stack {};", 'c' => "// Generic void* stack"],
         [['push 10\npush 20\npeek', '20', 1]]
        ],
        [14, 'dbms', 'Second Highest Salary', 'Medium', 'SQL Subquery',
         'Write an SQL query to find the second highest distinct salary from the <code>Employee</code> table.',
         ['Employee(id, salary)'],
         [['input' => '[100, 200, 300]', 'output' => '200', 'explanation' => 'Second highest is 200.']],
         ['python' => 'query = "SELECT MAX(salary) FROM Employee WHERE salary < (SELECT MAX(salary) FROM Employee);"' , 'java' => 'String sql = "";', 'cpp' => 'string sql = "";', 'c' => 'const char* sql = "";'],
         [['100 200 300', '200', 1]]
        ],
        [15, 'dbms', 'Department Top Three Salaries', 'Hard', 'Window Functions',
         'Find employees who earn one of the top 3 unique salaries in each department using DENSE_RANK().',
         ['Employee(id, name, salary, departmentId)'],
         [['input' => 'IT: [90k, 85k, 85k, 70k]', 'output' => 'All 4', 'explanation' => 'Three unique salaries: 90k, 85k, 70k.']],
         ['python' => 'query = "SELECT ..."' , 'java' => 'String sql = "";', 'cpp' => 'string sql = "";', 'c' => 'const char* sql = "";'],
         [['IT 90000 85000 70000', '90000 85000 70000', 1]]
        ],
        [16, 'dbms', 'Customers Who Never Order', 'Easy', 'SQL Joins',
         'Find all customers who never placed an order using LEFT JOIN or NOT EXISTS.',
         ['Customers(id, name), Orders(id, customerId)'],
         [['input' => 'Customers: Joe, Henry; Orders: Joe', 'output' => 'Henry', 'explanation' => 'Henry has no orders.']],
         ['python' => 'query = "SELECT name FROM Customers WHERE id NOT IN (SELECT customerId FROM Orders);"' , 'java' => 'String sql = "";', 'cpp' => 'string sql = "";', 'c' => 'const char* sql = "";'],
         [['Joe,Henry | Joe', 'Henry', 1]]
        ],
    ];

    $stmtProb = $pdo->prepare(
        'INSERT IGNORE INTO practice_problems
         (problem_number, subject_track, title, difficulty, topic, description, constraints_json, examples_json, starter_code_json)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $stmtProbId = $pdo->prepare('SELECT id FROM practice_problems WHERE subject_track = ? AND problem_number = ?');
    $stmtTcCount = $pdo->prepare('SELECT COUNT(*) FROM practice_testcases WHERE problem_id = ?');
    $stmtTcIns = $pdo->prepare('INSERT INTO practice_testcases (problem_id, input_data, expected_output, is_sample) VALUES (?,?,?,?)');

    $count = 0;
    foreach ($problems as [$num, $track, $title, $diff, $topic, $desc, $cons, $ex, $starter, $tcs]) {
        $stmtProb->execute([
            $num, $track, $title, $diff, $topic, $desc,
            json_encode($cons), json_encode($ex), json_encode($starter),
        ]);
        $count += $stmtProb->rowCount();
        $stmtProbId->execute([$track, $num]);
        $pid = (int) $stmtProbId->fetchColumn();
        $stmtTcCount->execute([$pid]);
        if ((int) $stmtTcCount->fetchColumn() === 0) {
            foreach ($tcs as [$in, $out, $isSample]) {
                $stmtTcIns->execute([$pid, $in, $out, $isSample]);
            }
        }
    }
    return $count;
}
